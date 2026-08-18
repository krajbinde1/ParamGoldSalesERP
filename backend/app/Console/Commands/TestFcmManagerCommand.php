<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use App\Models\Employee;
use App\Models\User;
use App\Enums\UserRole;
use App\Services\Notifications\FcmHttpClient;
use Illuminate\Console\Command;

/**
 * Temporary live FCM diagnostic — bypasses OrderObserver and sends a critical
 * data-only message to the Manager's currently stored device token(s).
 */
class TestFcmManagerCommand extends Command
{
    protected $signature = 'paramgold:test-fcm-manager
                            {managerId : Manager user ID, employee_id, or 10-digit mobile number}';

    protected $description = 'Send a live critical FCM data message to a Manager device token (diagnostic)';

    public function handle(FcmHttpClient $fcm): int
    {
        $raw = trim((string) $this->argument('managerId'));
        $resolved = $this->resolveManager($raw);

        $lookupType = $resolved['lookup_type'];
        $manager = $resolved['user'];
        $mobile = $resolved['mobile'];

        $this->line('LOOKUP_TYPE='.$lookupType);

        if ($manager === null) {
            $this->line('MANAGER_FOUND=NO');
            $this->line('USER_ID=');
            $this->line('EMPLOYEE_ID=');
            $this->line('MANAGER_NAME=');
            $this->line('MOBILE='.($mobile ?? ''));
            $this->line('TOKEN_COUNT=0');
            $this->line('TOKEN_MASKED=');
            $this->line('FCM_SEND_SUCCESS=NO');
            $this->line('FCM_HTTP_STATUS=');
            $this->line('FCM_RESPONSE=');
            $this->error('ROOT_CAUSE='.($resolved['root_cause'] ?? 'MANAGER_NOT_FOUND'));

            return self::FAILURE;
        }

        $manager->loadMissing('employee:id,full_name,mobile');

        $this->line('MANAGER_FOUND=YES');
        $this->line('USER_ID='.$manager->id);
        $this->line('EMPLOYEE_ID='.(string) ($manager->employee_id ?? $manager->employee?->id ?? ''));
        $this->line('MANAGER_NAME='.$this->managerName($manager));
        $this->line('MOBILE='.(string) (
            $mobile
            ?? $manager->employee?->mobile
            ?? $manager->login_id
            ?? ''
        ));
        $this->line('MANAGER_ROLE='.((string) $manager->role));
        $this->line('MANAGER_JOB_ROLE='.((string) ($manager->job_role ?? '')));
        $this->line('IS_MANAGER_USER='.($manager->hasRole(UserRole::Manager) ? 'YES' : 'NO'));

        $tokens = DeviceToken::query()
            ->where('user_id', $manager->id)
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->pluck('token')
            ->filter(fn ($t) => is_string($t) && $t !== '')
            ->values()
            ->all();

        $this->line('TOKEN_COUNT='.count($tokens));

        if ($tokens === []) {
            $this->line('TOKEN_MASKED=');
            $this->line('FCM_SEND_SUCCESS=NO');
            $this->line('FCM_HTTP_STATUS=');
            $this->line('FCM_RESPONSE=');
            $this->error('ROOT_CAUSE=MANAGER_DEVICE_TOKEN_NOT_REGISTERED');
            $this->warn('Manager must open the live APK, log in, and allow notification permission so getToken() registers.');

            return self::FAILURE;
        }

        foreach ($tokens as $index => $token) {
            $this->line('TOKEN_MASKED['.$index.']='.$this->maskToken($token));
        }
        $this->line('TOKEN_MASKED='.$this->maskToken($tokens[0]));

        $backendProject = (string) config('firebase.project_id');
        $mobileProject = $this->mobileFirebaseProjectId();
        $this->line('BACKEND_PROJECT='.$backendProject);
        $this->line('MOBILE_PROJECT='.($mobileProject ?? 'UNKNOWN'));
        $projectMatch = $mobileProject !== null
            && $backendProject !== ''
            && $backendProject === $mobileProject;
        $this->line('PROJECT_MATCH='.($projectMatch ? 'YES' : 'NO'));

        if ($mobileProject !== null && ! $projectMatch) {
            $this->line('FCM_SEND_SUCCESS=NO');
            $this->line('FCM_HTTP_STATUS=');
            $this->line('FCM_RESPONSE=');
            $this->error('ROOT_CAUSE=FIREBASE_PROJECT_MISMATCH');

            return self::FAILURE;
        }

        if (! $fcm->isConfigured()) {
            $this->line('FIREBASE_PROJECT_ID='.$backendProject);
            $this->line('FCM_SEND_SUCCESS=NO');
            $this->line('FCM_HTTP_STATUS=');
            $this->line('FCM_RESPONSE=FCM_NOT_CONFIGURED');
            $this->error('ROOT_CAUSE=FCM_NOT_CONFIGURED');
            $this->warn('Check FIREBASE_PROJECT_ID and firebase-service-account.json on this server.');

            return self::FAILURE;
        }

        $this->line('FIREBASE_PROJECT_ID='.$backendProject);
        $this->line('SENDING_CRITICAL_DATA_MESSAGE=YES');
        $this->line('PAYLOAD_TYPE=diagnostic_test');
        $this->line('PAYLOAD_FULLSCREEN=1');
        $this->line('PAYLOAD_ORDER_ID=TEST-LIVE-FCM');

        $result = $fcm->sendToTokens(
            tokens: $tokens,
            notification: [
                'title' => 'ParamGold Live Test',
                'body' => 'Live FCM notification test',
            ],
            data: [
                'type' => 'diagnostic_test',
                'title' => 'ParamGold Live Test',
                'body' => 'Live FCM notification test',
                'fullscreen' => '1',
                'order_id' => 'TEST-LIVE-FCM',
                'click_action' => 'OPEN_ORDER',
                'channel_id' => FcmHttpClient::CHANNEL_CRITICAL,
            ],
        );

        $details = $result['details'] ?? [];
        $first = is_array($details[0] ?? null) ? $details[0] : [];

        $httpStatus = (string) ($first['http_status'] ?? '');
        $sendOk = (($result['success'] ?? 0) > 0);
        $messageName = (string) ($first['message_name'] ?? '');
        $errorStatus = (string) ($first['error_status'] ?? '');
        $errorMessage = (string) ($first['error_message'] ?? '');

        $this->line('FCM_HTTP_STATUS='.$httpStatus);
        $this->line('FCM_SEND_SUCCESS='.($sendOk ? 'YES' : 'NO'));
        $this->line('FCM_MESSAGE_NAME='.$messageName);
        $this->line('FCM_SUCCESS_COUNT='.(string) ($result['success'] ?? 0));
        $this->line('FCM_FAILURE_COUNT='.(string) ($result['failure'] ?? 0));
        $this->line(
            'FCM_RESPONSE='.($sendOk
                ? ($messageName !== '' ? $messageName : 'OK')
                : trim($errorStatus.' '.$errorMessage)),
        );

        if (! $sendOk) {
            $this->line('FCM_ERROR_STATUS='.$errorStatus);
            $this->line('FCM_ERROR_MESSAGE='.$errorMessage);

            $root = match (true) {
                str_contains(strtoupper($errorStatus), 'UNREGISTERED'),
                str_contains(strtoupper($errorMessage), 'UNREGISTERED') => 'UNREGISTERED_TOKEN',
                str_contains(strtoupper($errorStatus), 'SENDER_ID_MISMATCH'),
                str_contains(strtoupper($errorMessage), 'SENDER_ID_MISMATCH') => 'SENDER_ID_MISMATCH',
                str_contains(strtoupper($errorStatus), 'INVALID_ARGUMENT'),
                str_contains(strtoupper($errorMessage), 'INVALID_ARGUMENT') => 'INVALID_ARGUMENT',
                str_contains(strtoupper($errorStatus), 'PERMISSION_DENIED'),
                str_contains(strtoupper($errorMessage), 'PERMISSION_DENIED') => 'PERMISSION_DENIED',
                str_contains(strtolower($errorMessage), 'auth') => 'AUTHENTICATION_ERROR',
                default => 'FCM_SEND_FAILED',
            };
            $this->error('ROOT_CAUSE='.$root);

            return self::FAILURE;
        }

        $this->info('ROOT_CAUSE=NONE_SERVER_SEND_OK');
        $this->warn('If the Manager phone shows nothing, check logcat for PARAMGOLD_LIVE_FCM:FCM_RECEIVED (app must be installed, logged in, notifications allowed).');
        $this->warn('Lock the Manager phone screen to verify full-screen; unlocked may show heads-up only.');

        return self::SUCCESS;
    }

    /**
     * @return array{lookup_type: string, user: ?User, mobile: ?string, root_cause: ?string}
     */
    private function resolveManager(string $raw): array
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        // 10-digit Indian mobile (or 12-digit with 91 prefix → last 10).
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 10) {
            return $this->resolveByMobile($digits);
        }

        if (! ctype_digit($raw)) {
            return [
                'lookup_type' => 'INVALID',
                'user' => null,
                'mobile' => null,
                'root_cause' => 'MANAGER_NOT_FOUND',
            ];
        }

        $id = (int) $raw;
        $byUserId = User::query()->find($id);
        if ($byUserId !== null) {
            if (! $byUserId->hasRole(UserRole::Manager)) {
                return [
                    'lookup_type' => 'USER_ID',
                    'user' => null,
                    'mobile' => null,
                    'root_cause' => 'USER_FOUND_BUT_NOT_MANAGER',
                ];
            }

            return [
                'lookup_type' => 'USER_ID',
                'user' => $byUserId,
                'mobile' => null,
                'root_cause' => null,
            ];
        }

        $byEmployeeId = User::query()->where('employee_id', $id)->first();
        if ($byEmployeeId !== null) {
            if (! $byEmployeeId->hasRole(UserRole::Manager)) {
                return [
                    'lookup_type' => 'EMPLOYEE_ID',
                    'user' => null,
                    'mobile' => null,
                    'root_cause' => 'USER_FOUND_BUT_NOT_MANAGER',
                ];
            }

            return [
                'lookup_type' => 'EMPLOYEE_ID',
                'user' => $byEmployeeId,
                'mobile' => null,
                'root_cause' => null,
            ];
        }

        return [
            'lookup_type' => 'ID',
            'user' => null,
            'mobile' => null,
            'root_cause' => 'MANAGER_NOT_FOUND',
        ];
    }

    /**
     * @return array{lookup_type: string, user: ?User, mobile: ?string, root_cause: ?string}
     */
    private function resolveByMobile(string $mobile): array
    {
        // 1) employees.mobile → employee.user
        $employee = Employee::query()
            ->with('user')
            ->where('mobile', $mobile)
            ->first();

        if ($employee?->user !== null) {
            if (! $employee->user->hasRole(UserRole::Manager)) {
                return [
                    'lookup_type' => 'MOBILE',
                    'user' => null,
                    'mobile' => $mobile,
                    'root_cause' => 'USER_FOUND_BUT_NOT_MANAGER',
                ];
            }

            return [
                'lookup_type' => 'MOBILE',
                'user' => $employee->user,
                'mobile' => $mobile,
                'root_cause' => null,
            ];
        }

        // 2) users.login_id (often the mobile used at login)
        $byLogin = User::query()
            ->with('employee:id,full_name,mobile')
            ->where('login_id', $mobile)
            ->first();

        if ($byLogin !== null) {
            if (! $byLogin->hasRole(UserRole::Manager)) {
                return [
                    'lookup_type' => 'MOBILE',
                    'user' => null,
                    'mobile' => $mobile,
                    'root_cause' => 'USER_FOUND_BUT_NOT_MANAGER',
                ];
            }

            return [
                'lookup_type' => 'MOBILE',
                'user' => $byLogin,
                'mobile' => $mobile,
                'root_cause' => null,
            ];
        }

        // 3) users.employee_id via employees.mobile when user relation missing above
        if ($employee !== null && $employee->user === null) {
            $byEmployeeFk = User::query()
                ->where('employee_id', $employee->id)
                ->first();
            if ($byEmployeeFk !== null) {
                if (! $byEmployeeFk->hasRole(UserRole::Manager)) {
                    return [
                        'lookup_type' => 'MOBILE',
                        'user' => null,
                        'mobile' => $mobile,
                        'root_cause' => 'USER_FOUND_BUT_NOT_MANAGER',
                    ];
                }

                return [
                    'lookup_type' => 'MOBILE',
                    'user' => $byEmployeeFk,
                    'mobile' => $mobile,
                    'root_cause' => null,
                ];
            }
        }

        return [
            'lookup_type' => 'MOBILE',
            'user' => null,
            'mobile' => $mobile,
            'root_cause' => 'MANAGER_NOT_FOUND',
        ];
    }

    private function managerName(User $manager): string
    {
        $name = trim((string) ($manager->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        $manager->loadMissing('employee:id,full_name');

        return trim((string) ($manager->employee?->full_name ?? ''));
    }

    private function maskToken(string $token): string
    {
        $len = strlen($token);
        if ($len <= 16) {
            return str_repeat('*', max(0, $len - 4)).substr($token, -4);
        }

        return substr($token, 0, 8).'...'.substr($token, -6);
    }

    private function mobileFirebaseProjectId(): ?string
    {
        $candidates = [
            base_path('../mobile/android/app/google-services.json'),
            base_path('../../mobile/android/app/google-services.json'),
            dirname(base_path()).'/mobile/android/app/google-services.json',
        ];

        foreach ($candidates as $path) {
            if (! is_file($path)) {
                continue;
            }
            $json = json_decode((string) file_get_contents($path), true);
            $projectId = $json['project_info']['project_id'] ?? null;
            if (is_string($projectId) && $projectId !== '') {
                return $projectId;
            }
        }

        return null;
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

class EmployeeAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'login_id' => ['required', 'string', 'min:4', 'max:32', 'regex:/^[A-Za-z0-9]+$/'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->with(['employee.reportingManager'])
            ->where('login_id', $credentials['login_id'])
            ->whereIn('role', UserRole::mobileValues())
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid login ID or password.',
            ], 422);
        }

        if (! $user->canLoginToMobile()) {
            return response()->json([
                'success' => false,
                'message' => 'This employee account is inactive.',
            ], 403);
        }

        $user->tokens()->where('name', 'employee-mobile')->delete();
        $token = $user->createToken('employee-mobile', ['employee-mobile'])->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'user' => $this->userData($user),
            'employee' => $this->employeeData($user->employee),
            'permissions' => $user->roleEnum()->mobilePermissions(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['employee.reportingManager']);

        if (! $user->canLoginToMobile()) {
            $request->user()->currentAccessToken()?->delete();

            return response()->json([
                'success' => false,
                'message' => 'This employee account is inactive.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'user' => $this->userData($user),
            'employee' => $this->employeeData($user->employee),
            'permissions' => $user->roleEnum()->mobilePermissions(),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => [
                'required',
                'confirmed',
                'different:current_password',
                Password::min(8)->mixedCase()->numbers()->symbols(),
            ],
        ]);

        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'The current password is incorrect.',
            ], 422);
        }

        $user->update([
            'password' => Hash::make($data['password']),
            'must_change_password' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.',
            'user' => $this->userData($user->fresh()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'employee_id' => $user->employee_id,
            'login_id' => $user->login_id,
            'role' => $user->role,
            'role_label' => $user->roleEnum()->label(),
            'must_change_password' => $user->must_change_password,
        ];
    }

    private function employeeData(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'full_name' => $employee->full_name,
            'mobile' => $employee->mobile,
            'email' => $employee->email,
            'department' => $employee->department,
            'designation' => $employee->designation,
            'reporting_manager' => $employee->reportingManager?->full_name,
            'base_location' => $employee->base_location,
            'joining_date' => $employee->joining_date?->toDateString(),
            'profile_photo_url' => $employee->profile_photo_path
                ? Storage::disk('public')->url($employee->profile_photo_path)
                : null,
            'active' => (bool) $employee->status,
        ];
    }
}

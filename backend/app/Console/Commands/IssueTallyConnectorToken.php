<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TallySync\TallyConnectorAuth;
use Illuminate\Console\Command;

class IssueTallyConnectorToken extends Command
{
    protected $signature = 'tally-connector:issue-token
                            {--user-id= : User ID that owns the connector token}
                            {--login-id= : Login ID that owns the connector token}';

    protected $description = 'Issue a Sanctum token for the local Tally connector (does not call Tally)';

    public function handle(): int
    {
        $userId = $this->option('user-id');
        $loginId = $this->option('login-id');

        if (blank($userId) && blank($loginId)) {
            $this->error('Pass --user-id or --login-id for the user that should own this connector token.');

            return self::FAILURE;
        }

        $user = User::query()
            ->when(filled($userId), fn ($query) => $query->whereKey((int) $userId))
            ->when(filled($loginId), fn ($query) => $query->where('login_id', (string) $loginId))
            ->first();

        if ($user === null) {
            $this->error('No user matched the given --user-id / --login-id.');

            return self::FAILURE;
        }

        $user->tokens()
            ->where('name', TallyConnectorAuth::TOKEN_NAME)
            ->delete();

        $token = $user->createToken(
            TallyConnectorAuth::TOKEN_NAME,
            [TallyConnectorAuth::ABILITY],
        );

        $this->info('Tally connector token created for user #'.$user->id.' ('.$user->name.').');
        $this->line('Store this token on the office PC only. It will not be shown again:');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}

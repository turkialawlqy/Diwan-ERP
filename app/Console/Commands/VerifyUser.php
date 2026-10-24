<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Marks a user's email as verified by hand: for a server whose mail is not
 * set up yet, or a client who cannot receive the verification email.
 */
class VerifyUser extends Command
{
    protected $signature = 'erp:verify-user {email}';

    protected $description = 'Mark a user email as verified (when the verification email cannot be received)';

    public function handle(): int
    {
        $user = User::withoutGlobalScopes()->where('email', strtolower(trim($this->argument('email'))))->first();
        if (! $user) {
            $this->error('لا يوجد مستخدم بهذا البريد.');

            return self::FAILURE;
        }
        if ($user->hasVerifiedEmail()) {
            $this->info('البريد مؤكد مسبقاً.');

            return self::SUCCESS;
        }
        $user->markEmailAsVerified();
        $this->info("تم تأكيد بريد {$user->email}، ويمكنه الدخول الآن.");

        return self::SUCCESS;
    }
}

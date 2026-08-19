<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserSessionRepository;
use App\Services\PasswordPolicyService;
use App\Services\UserService;

/**
 * The signed-in user's own profile.
 *
 * Separate from the user administration pages on purpose: what a person may do
 * to their own account and what an administrator may do to somebody else's are
 * different questions, and mixing them in one controller is how the answer to
 * the second quietly becomes the answer to the first.
 *
 * @package App\Controllers\Web
 * @version 1.0.0
 */
final class ProfileController extends Controller
{
    /**
     * GET /profile
     */
    public function show(Request $request): Response
    {
        $userId = (int) $this->auth->id();

        return $this->render('pages/profile/index', [
            'title'          => 'My profile',
            'profile'        => $this->service(UserService::class)->profile($userId),
            'sessions'       => $this->service(UserSessionRepository::class)->activeFor($userId),
            'activity'       => $this->service(AuditLogRepository::class)->forUser($userId, 20),
            'passwordExpiry' => $this->service(PasswordPolicyService::class)->daysUntilExpiry(
                $this->auth->user()?->passwordChangedAt
            ),
        ]);
    }

    /**
     * POST /profile
     */
    public function update(Request $request): Response
    {
        $attributes = $this->validate($request, [
            'first_name'    => 'required|alpha_space|max:60',
            'middle_name'   => 'nullable|alpha_space|max:60',
            'last_name'     => 'required|alpha_space|max:60',
            'email'         => 'required|email|max:150',
            'mobile_number' => 'nullable|phone',
        ], [
            'first_name'    => 'First name',
            'last_name'     => 'Last name',
            'email'         => 'Email address',
            'mobile_number' => 'Mobile number',
        ]);

        $this->service(UserService::class)->update((int) $this->auth->id(), $attributes);

        return $this->redirect('/profile')->withSuccess('Your profile was updated.');
    }
}

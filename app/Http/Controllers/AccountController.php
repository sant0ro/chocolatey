<?php

namespace App\Http\Controllers;

use App\Facades\Session;
use App\Models\ChocolateyId;
use App\Models\User;
use App\Models\UserPreferences;
use App\Models\UserSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Laravel\Lumen\Routing\Controller as BaseController;

/**
 * Class AccountController
 * @package App\Http\Controllers
 */
class AccountController extends BaseController
{
    /**
     * Check an User Name
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function checkName(Request $request): JsonResponse
    {
        if (User::where('username', $request->json()->get('name'))->count() > 0 && 
            !$request->json()->get('name') != $request->user()->name)
            return response()->json(['code' => 'NAME_IN_USE', 'validationResult' => null, 'suggestions' => []]);

        if (strlen($request->json()->get('name')) >= 50 || strpos($request->json()->get('name'), 'MOD_') !== false)
            return response()->json(['code' => 'INVALID_NAME', 'validationResult' => null, 'suggestions' => []]);

        return response()->json(['code' => 'OK', 'validationResult' => null, 'suggestions' => []]);
    }

    /**
     * Select an User Name
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function selectName(Request $request): JsonResponse
    {
        $request->user()->update(['username' => $request->json()->get('name')]);

        Session::set(Config::get('chocolatey.security.session'), $request->user());

        return response()->json(['code' => 'OK', 'validationResult' => null, 'suggestions' => []]);
    }

    /**
     * Save User Look
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function saveLook(Request $request): JsonResponse
    {
        $request->user()->update([
            'look' => $request->json()->get('figure'),
            'gender' => $request->json()->get('gender')]);

        Session::set(Config::get('chocolatey.security.session'), $request->user());

        return response()->json($request->user());
    }

    /**
     * Select a Room
     *
     * @TODO: Generate the Room for the User
     * @TODO: Get Room Models.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function selectRoom(Request $request): JsonResponse
    {
        if (!in_array($request->json()->get('roomIndex'), [1, 2, 3]))
            return response('', 400);

        $request->user()->traits = ["USER"];

        return response()->json('');
    }

    /**
     * Get User Non Read Messenger Discussions
     *
     * @TODO: Code Integration with HabboMessenger
     * @TODO: Create Messenger Model
     *
     * @return JsonResponse
     */
    public function getDiscussions(): JsonResponse
    {
        return response()->json([]);
    }

    /**
     * Get User Preferences
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getPreferences(Request $request): JsonResponse
    {
        $userPreferences = UserPreferences::find($request->user()->uniqueId);

        foreach ($userPreferences->getAttributes() as $attributeName => $attributeValue)
            $userPreferences->{$attributeName} = $attributeValue == 1;

        return response()->json($userPreferences);
    }

    /**
     * Save New User Preferences
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function savePreferences(Request $request): JsonResponse
    {
        UserSettings::updateOrCreate([
            'user_id' => $request->user()->uniqueId,
            'block_following' => $request->json()->get('friendCanFollow') == false,
            'block_friendrequests' => $request->json()->get('friendRequestEnabled') == false
        ]);

        UserPreferences::find($request->user()->uniqueId)->update((array)$request->json()->all());

        return response()->json('');
    }

    /**
     * Get All E-Mail Accounts
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAvatars(Request $request): JsonResponse
    {
        $azureIdAccounts = ChocolateyId::where('mail', $request->user()->email)->first();

        return response()->json($azureIdAccounts->relatedAccounts);
    }

    /**
     * Check if an Username is available
     * for a new Avatar Account
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function checkNewName(Request $request): JsonResponse
    {
        if (User::where('username', $request->input('name'))->count() > 0)
            return response()->json(['isAvailable' => false]);

        return response()->json(['isAvailable' => true]);
    }

    /**
     * Create a New User Avatar
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createAvatar(Request $request): JsonResponse
    {
        if (User::where('username', $request->json()->get('name'))->count() > 0)
            return response()->json(['isAvailable' => false]);

        $request->user()->name = $request->json()->get('name');

        $this->createUser($request, $request->user()->getAttributes());

        return response()->json('');
    }

    /**
     * Create a New User
     *
     * @param Request $request
     * @param array $userInfo
     * @param bool $newUser If is a New User
     * @return User
     */
    public function createUser(Request $request, array $userInfo, bool $newUser = false): User
    {
        $userName = $newUser ? uniqid(strstr($userInfo['email'], '@', true)) : $userInfo['username'];
        $userMail = $newUser ? $userInfo['email'] : $userInfo['mail'];

        $mailController = new MailController;

        $mailController->send([
            'mail' => $userMail,
            'name' => $userName,
            'url' => "/activate/{$mailController
            ->prepare($userMail, 'public/registration/activate')}"
        ]);

        $userData = new User;
        $userData->store($userName, $userInfo['password'], $userMail, $request->ip())->save();
        $userData->createData();

        Session::set(Config::get('chocolatey.security.session'), $userData);

        return $userData;
    }

    /**
     * Change Logged In User
     *
     * @param Request $request
     */
    public function selectAvatar(Request $request)
    {
        $userData = User::find($request->json()->get('uniqueId'));

        Session::set(Config::get('chocolatey.security.session'), $userData);
    }

    /**
     * Confirm E-Mail Activation
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function confirmActivation(Request $request): JsonResponse
    {
        if (($mail = (new MailController)->getMail($request->json()->get('token'))) == null)
            return response()->json(['error' => 'activation.invalid_token'], 400);

        if (strpos($mail->link, 'change-email') !== false):
            $mail = str_replace('change-email/', '', $mail->link);

            User::where('mail', $mail->mail)->update(['mail' => $mail]);

            ChocolateyId::where('mail', $mail->mail)->update(['mail' => $mail]);
        endif;

        User::where('mail', $mail->mail)->update(['mail_verified' => '1']);

        return response()->json(['email' => $mail->mail, 'emailVerified' => true, 'identityVerified' => true]);
    }

    /**
     * Send User Forgot E-Mail
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        if (($user = User::where('mail', $request->json()->get('email'))->first()) == null)
            return response()->json(['email' => $request->json()->get('email')]);

        $mailController = new MailController;

        $mailController->send([
            'name' => $user->name,
            'mail' => $user->email,
            'url' => "/reset-password/{$mailController->prepare($user->email, 'public/forgotPassword')}"
        ], 'habbo-web-mail.password-reset');

        return response()->json(['email' => $user->email]);
    }
}

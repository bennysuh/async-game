<?php

namespace NanokaWeb\AsyncGame\Api\V1\Controllers;

use NanokaWeb\AsyncGame\Api\V1\Requests\LoginUserRequest;
use NanokaWeb\AsyncGame\Api\V1\Requests\SignupUserRequest;
use Tymon\JWTAuth\Facades\JWTAuth;
use Validator;
use Config;
use NanokaWeb\AsyncGame\User;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Dingo\Api\Routing\Helpers;
use Illuminate\Support\Facades\Password;
use Tymon\JWTAuth\Exceptions\JWTException;
use Dingo\Api\Exception\ValidationHttpException;

/**
 * Class AuthController
 *
 * @package NanokaWeb\AsyncGame\Api\V1\Controllers
 */
class AuthController extends Controller
{
    /**
     * Token-based Authentication login.
     *
     * @param  LoginUserRequest   $request
     *
     * @return Response
     *
     * @api               {post} /v1/auth/login User Login
     * @apiVersion        1.0.0
     * @apiName           AuthLogin
     * @apiGroup          Authentication
     *
     * @apiParam {String} email               Email of the User.
     * @apiParam {String} Password            Password of the User.
     *
     * @apiExample {curl} Example usage:
     *     curl -i https://asyncgame.nanoka.fr/api/v1/auth/login -X POST -d "email=john.doe@nanoka.fr&password=mypassword"
     *
     * @apiSuccess {String}   token      Authentication token.
     *
     * @apiUse ApiLimitError
     */
    public function login(LoginUserRequest $request)
    {
        $credentials = $request->only(['email', 'password']);

        try {
            if (! $token = JWTAuth::attempt($credentials)) {
                return $this->response->errorUnauthorized();
            }
        } catch (JWTException $e) {
            return $this->response->error('could_not_create_token', 500);
        }

        return response()->json(compact('token'));
    }

    /**
     * Authentication signup.
     *
     * @param  SignupUserRequest   $request
     *
     * @return Response
     *
     * @api               {post} /v1/auth/login User Signup
     * @apiVersion        1.0.0
     * @apiName           AuthSignup
     * @apiGroup          Authentication
     *
     * @apiParam {String} email               Email of the User.
     * @apiParam {String} Password            Password of the User.
     * @apiParam {String} first_name          Firstname of the User.
     * @apiParam {String} last_name           Lastname of the User.
     *
     * @apiExample {curl} Example usage:
     *     curl -i https://asyncgame.nanoka.fr/api/v1/auth/signup -X POST -d "email=john.doe@nanoka.fr&password=mypassword&first_name=John&last_name=Doe"
     *
     * @apiSuccess {String}   token      Authentication token.
     *
     * @apiUse ApiLimitError
     */
    public function signup(SignupUserRequest $request, LoginUserRequest $loginUserRequest)
    {
        $signupFields = Config::get('async-game.signup_fields');
        $hasToReleaseToken = Config::get('async-game.signup_token_release');

        $userData = $request->only($signupFields);

        User::unguard();
        $user = User::create($userData);
        User::reguard();

        if(!$user->id) {
            return $this->response->error('could_not_create_user', 500);
        }

        if($hasToReleaseToken) {
            return $this->login($loginUserRequest);
        }

        return $this->response->created();
    }

    public function recovery(Request $request)
    {
        $validator = Validator::make($request->only('email'), [
            'email' => 'required'
        ]);

        if($validator->fails()) {
            throw new ValidationHttpException($validator->errors()->all());
        }

        $response = Password::sendResetLink($request->only('email'), function (Message $message) {
            $message->subject(Config::get('async-game.recovery_email_subject'));
        });

        switch ($response) {
            case Password::RESET_LINK_SENT:
                return $this->response->noContent();
            case Password::INVALID_USER:
                return $this->response->errorNotFound();
        }
    }

    public function reset(Request $request)
    {
        $credentials = $request->only(
            'email', 'password', 'password_confirmation', 'token'
        );

        $validator = Validator::make($credentials, [
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|confirmed|min:6',
        ]);

        if($validator->fails()) {
            throw new ValidationHttpException($validator->errors()->all());
        }

        $response = Password::reset($credentials, function ($user, $password) {
            $user->password = $password;
            $user->save();
        });

        switch ($response) {
            case Password::PASSWORD_RESET:
                if(Config::get('async-game.reset_token_release')) {
                    return $this->login($request);
                }
                return $this->response->noContent();

            default:
                return $this->response->error('could_not_reset_password', 500);
        }
    }
}

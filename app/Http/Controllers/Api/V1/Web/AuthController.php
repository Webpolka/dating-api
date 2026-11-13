<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CaptchaService;
use Carbon\Carbon;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    protected CaptchaService $captchaService;

    public function __construct(CaptchaService $captchaService)
    {
        $this->captchaService = $captchaService;
    }

    // ============================================
    // КАПЧА (Публичная)
    // ============================================

    public function getCaptchaLogin()
    {
        return response()->json(['image' => $this->captchaService->generate('login_captcha')]);
    }

    public function getCaptchaForgot()
    {
        return response()->json(['image' => $this->captchaService->generate('forgot_password_captcha')]);
    }

    public function refreshRegisterCaptcha()
    {
        return response()->json(['captchaImage' => $this->captchaService->generate('register_captcha')]);
    }

    // ============================================
    // ЛОГИН И ВЫХОД
    // ============================================

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
            'captchaInput' => 'required|string',
        ], [
            'captchaInput.required' => 'Введите код с картинки.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Проверка капчи
        if (!$this->captchaService->validate('login_captcha', $request->captchaInput)) {
            return response()->json([
                'errors' => ['captchaInput' => ['Неверный код с картинки. Попробуйте снова.']]
            ], 422);
        }

        if (!Auth::attempt(['email' => $request->email, 'password' => $request->password], $request->boolean('remember'))) {
            return response()->json([
                'errors' => ['email' => ['Неверный email или пароль.']]
            ], 422);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::user();

        if ($user->isBanned()) {
            Auth::logout();
            return response()->json([
                'errors' => ['email' => ['Ваш аккаунт заблокирован.']]
            ], 422);
        }

        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $redirectUrl = '/'; // По умолчанию
        if ($user->isStaff()) {
            $redirectUrl = '/admin';
        } elseif (! $user->hasCompletedOnboarding()) {
            $redirectUrl = '/onboarding';
        }

        return response()->json(['success' => true, 'redirect' => $redirectUrl]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['success' => true]);
    }

    // ============================================
    // ПОЛУЧЕНИЕ ЮЗЕРА (для инициализации Next.js)
    // ============================================

    public function user(Request $request)
    {
        return response()->json([
            'user' => $request->user()
        ]);
    }

    // ============================================
    // РЕГИСТРАЦИЯ (Мульти-шаговая)
    // ============================================

    public function validateStep1(Request $request)
    {
        $rules = [
            'birth_day' => ['required', 'integer', 'between:1,31'],
            'birth_month' => ['required', 'integer', 'between:1,12'],
            'birth_year' => ['required', 'integer', 'between:1950,2010'],
            'name' => ['required', 'string', 'max:255'],
            'gender' => ['required', 'in:male,female'],
        ];

        if ($errorResponse = $this->validateJson($request, $rules)) {
            return $errorResponse;
        }

        $validated = $request->only(array_keys($rules));

        if (!checkdate((int)$validated['birth_month'], (int)$validated['birth_day'], (int)$validated['birth_year'])) {
            return response()->json([
                'errors' => ['birth_day' => ['Указана несуществующая дата рождения.']]
            ], 422);
        }

        return response()->json(['success' => true]);
    }

    public function validateStep2(Request $request)
    {
        $rules = [
            'dating_goals' => ['required', 'array', 'min:1'],
            'dating_goals.*' => ['required', 'in:friends,romantic,family,casual,travel'],
        ];

        if ($errorResponse = $this->validateJson($request, $rules)) {
            return $errorResponse;
        }

        $captchaImage = $this->captchaService->generate('register_captcha');

        return response()->json(['success' => true, 'captchaImage' => $captchaImage]);
    }

    public function register(Request $request)
    {
        $rules = [
            'name'           => ['required', 'string', 'max:255'],
            'gender'         => ['required', 'in:male,female'],
            'birth_day'      => ['required', 'integer', 'between:1,31'],
            'birth_month'    => ['required', 'integer', 'between:1,12'],
            'birth_year'     => ['required', 'integer', 'between:1950,2010'],
            'dating_goals'   => ['required', 'array', 'min:1'],
            'dating_goals.*' => ['required', 'in:friends,romantic,family,casual,travel'],
            'city_id'        => ['nullable', 'integer', 'exists:cities,id'],
            'country_id'     => ['nullable', 'integer', 'exists:countries,id'],
            'email'          => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'password'       => ['required', 'string', Rules\Password::defaults()],
            'captchaInput'   => ['required', 'string'],
        ];

        if ($errorResponse = $this->validateJson($request, $rules)) {
            return $errorResponse;
        }

        $validated = $request->only(array_keys($rules));

        // Проверка даты
        if (!checkdate((int)$validated['birth_month'], (int)$validated['birth_day'], (int)$validated['birth_year'])) {
            return response()->json([
                'errors' => ['birth_day' => ['Указана несуществующая дата рождения.']]
            ], 422);
        }

        // Хоть что-то одно должно быть выбрано (город или страна)
        if (empty($validated['city_id']) && empty($validated['country_id'])) {
            return response()->json([
                'errors' => ['city_id' => ['Укажите город или страну.']]
            ], 422);
        }

        // Капча
        if (!$this->captchaService->validate('register_captcha', $validated['captchaInput'])) {
            $newCaptcha = $this->captchaService->generate('register_captcha');
            return response()->json([
                'errors' => ['captchaInput' => ['Неверный код с картинки. Попробуйте снова.']],
                'captchaImage' => $newCaptcha
            ], 422);
        }

        // Создаём юзера
        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $birthDate = sprintf(
            '%04d-%02d-%02d',
            $validated['birth_year'],
            $validated['birth_month'],
            $validated['birth_day']
        );

        // Автораспространение country_id из города
        $countryId = $validated['country_id'] ?? null;
        if (!empty($validated['city_id']) && empty($countryId)) {
            $countryId = \App\Models\City::find($validated['city_id'])?->country_id;
        }

        $user->profile->update([
            'gender'       => $validated['gender'],
            'birth_date'   => $birthDate,
            'dating_goals' => $validated['dating_goals'],
            'city_id'      => $validated['city_id'] ?? null,
            'country_id'   => $countryId,
        ]);

        // ============================================
        // ОТПРАВКА ИВЕНТА С TRY-CATCH И SYNC FALLBACK
        // ============================================
        try {
            event(new Registered($user));
        } catch (\Throwable $e) {
            Log::warning('AuthController: dispatch Registered event failed, sending email sync', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            // Fallback: отправляем email синхронно (без очереди)
            try {
                $user->sendEmailVerificationNotification();
            } catch (\Throwable $e2) {
                Log::error('AuthController: sync email send also failed', [
                    'user_id' => $user->id,
                    'error'   => $e2->getMessage(),
                ]);
            }
        }

        Auth::login($user);

        return response()->json(['redirect' => '/onboarding']);
    }

    // ============================================
    // СБРОС ПАРОЛЯ
    // ============================================

    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'captchaInput' => 'required|string',
        ], [
            'captchaInput.required' => 'Введите код с картинки.',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!$this->captchaService->validate('forgot_password_captcha', $request->captchaInput)) {
            return response()->json([
                'errors' => ['captchaInput' => ['Неверный код с картинки. Попробуйте снова.']]
            ], 422);
        }

        $status = Password::sendResetLink(['email' => $request->email]);

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json(['success' => true]);
        } elseif ($status === Password::RESET_THROTTLED) {
            return response()->json([
                'errors' => ['email' => ['Слишком много попыток. Попробуйте позже.']]
            ], 422);
        } else {
            return response()->json([
                'errors' => ['email' => [__($status)]]
            ], 422);
        }
    }

    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->setRememberToken(Str::random(60));
                $user->save();
                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'success' => true,
                'redirect' => '/?login=1'
            ]);
        } else {
            return response()->json([
                'errors' => ['email' => [__($status)]]
            ], 422);
        }
    }

    // ============================================
    // ВЕРИФИКАЦИЯ EMAIL
    // ============================================

    public function verifyEmail(Request $request, $id, $hash)
    {
        $user = User::findOrFail($id);

        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return response()->json(['message' => 'Неверная ссылка верификации.'], 403);
        }

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        // Редиректим на фронтенд с флагом успеха
        // Берём первый URL из FRONTEND_URL (там может быть список через запятую)
        $frontendUrl = explode(',', config('app.frontend_url'))[0];

        return redirect(rtrim($frontendUrl, '/') . '/?verified=1');
    }

    public function resendVerification(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => true,
                'redirect' => '/'
            ]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['success' => true]);
    }

    public function changeEmail(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($user->id)],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user->email = $request->email;
        $user->email_verified_at = null;
        $user->save();

        $user->sendEmailVerificationNotification();

        return response()->json([
            'success' => true,
            'email' => $user->email
        ]);
    }

    // ============================================
    // ПОДТВЕРЖДЕНИЕ ПАРОЛЯ
    // ============================================

    public function confirmPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($request->password, $user->password)) {
            return response()->json([
                'errors' => ['password' => ['Введенный пароль неверен.']]
            ], 422);
        }

        $request->session()->put('auth.password_confirmed_at', time());

        // $redirectUrl = redirect()->intended('/')->getTargetUrl();

        return response()->json([
            'success' => true,
            'redirect' => '/' // <--- Жестко возвращаем на главную
        ]);
    }

    // ============================================
    // ХЕЛПЕР ДЛЯ JSON ВАЛИДАЦИИ
    // ============================================

    protected function validateJson(Request $request, array $rules)
    {
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors()
            ], 422);
        }

        return null;
    }
}

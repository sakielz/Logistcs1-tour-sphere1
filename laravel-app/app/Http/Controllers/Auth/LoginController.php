<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /**
     * Handle the login form submission.
     * Mirrors the authentication logic from the original login.php.
     */
    public function store(Request $request)
    {
        $request->validate([
            'email'    => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $identifier = trim($request->input('email'));
        $password   = $request->input('password');

        try {
            $user = DB::selectOne(
                "SELECT * FROM users
                 WHERE LOWER(TRIM(email))    = LOWER(TRIM(?))
                    OR LOWER(TRIM(username)) = LOWER(TRIM(?))
                 LIMIT 1",
                [$identifier, $identifier]
            );

            if (! $user) {
                throw ValidationException::withMessages([
                    'email' => 'No account found with that email address or username.',
                ]);
            }

            if (! (bool) $user->is_active) {
                throw ValidationException::withMessages([
                    'email' => 'This account is inactive. Please contact your Administrator.',
                ]);
            }

            if ((bool) $user->is_archived) {
                throw ValidationException::withMessages([
                    'email' => 'This account is archived. Please contact your Administrator.',
                ]);
            }

            if (empty($user->password)) {
                throw ValidationException::withMessages([
                    'email' => 'This account has no password set. Contact your Administrator.',
                ]);
            }

            if (! password_verify($password, $user->password)) {
                throw ValidationException::withMessages([
                    'email' => 'Incorrect password. Please try again.',
                ]);
            }

            // Successful login — store user in Laravel session
            $request->session()->regenerate();
            $request->session()->put([
                'user_id'       => $user->id,
                'username'      => $user->username,
                'role'          => $user->role,
                'full_name'     => $user->full_name,
                'email'         => $user->email,
                'last_activity' => time(),
            ]);

            // Rehash password if needed
            if (password_needs_rehash($user->password, PASSWORD_DEFAULT)) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                DB::update("UPDATE users SET password = ? WHERE id = ?", [$newHash, $user->id]);
            }

            // Update last login timestamp
            DB::update("UPDATE users SET last_login = NOW() WHERE id = ?", [$user->id]);

            return redirect('/admin/dashboard.php');

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'email' => 'Login error: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Log the user out.
     */
    public function destroy(Request $request)
    {
        $request->session()->flush();
        $request->session()->regenerate();

        return redirect()->route('login');
    }
}

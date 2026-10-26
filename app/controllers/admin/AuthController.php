<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;

final class AuthController
{
    /** /admin - send to the dashboard or the login form. */
    public function index(): never
    {
        admin_headers();
        redirect(Auth::check() ? 'admin/dashboard' : 'admin/login');
    }

    public function showLogin(): string
    {
        admin_headers();
        if (Auth::check()) {
            redirect('admin/dashboard');
        }

        return view('admin/login', [
            'title'  => 'Admin sign in',
            'errors' => errors(),
        ], 'admin-auth');
    }

    public function login(): never
    {
        admin_headers();

        if (!csrf_valid()) {
            flash('error', 'Your session expired or the form was out of date. Please try again.');
            redirect('admin/login');
        }

        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $errors = [];
        if ($email === '') {
            $errors['email'] = 'Enter your email address.';
        } elseif (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($password === '') {
            $errors['password'] = 'Enter your password.';
        } elseif (strlen($password) > 1024) {
            $errors['password'] = 'Password is too long.';
        }

        if ($errors) {
            with_input(['email' => mb_substr($email, 0, 190)], $errors);
            redirect('admin/login');
        }

        [$status, $minutes] = Auth::attempt($email, $password);

        if ($status === Auth::LOCKED) {
            with_input(['email' => $email]);
            flash('error', sprintf(
                'Too many failed sign-in attempts. Please try again in %d minute%s.',
                $minutes,
                $minutes === 1 ? '' : 's'
            ));
            redirect('admin/login');
        }

        if ($status !== Auth::OK) {
            // Same message for unknown email and wrong password
            with_input(['email' => $email]);
            flash('error', 'Incorrect email or password.');
            redirect('admin/login');
        }

        $intended = $_SESSION['_admin_intended'] ?? 'admin/dashboard';
        unset($_SESSION['_admin_intended']);

        flash('success', 'Welcome back, ' . Auth::user()['name'] . '.');
        redirect(ltrim((string) $intended, '/'));
    }

    /** GET /admin/logout - confirmation only; signing out needs a POST. */
    public function confirmLogout(): string
    {
        $user = require_admin();
        admin_headers();

        return view('admin/logout', ['title' => 'Sign out', 'admin' => $user], 'admin');
    }

    public function logout(): never
    {
        admin_headers();

        if (!csrf_valid()) {
            flash('error', 'Could not sign out: the form was out of date. Please try again.');
            redirect(Auth::check() ? 'admin/dashboard' : 'admin/login');
        }

        Auth::logout();
        flash('success', 'You have been signed out.');
        redirect('admin/login');
    }
}

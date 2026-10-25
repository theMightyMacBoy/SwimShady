<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index()
    {
        return view('users.index', [
            'users' => User::query()->with('devices')->orderBy('username')->get(),
            'devices' => Device::query()->orderBy('name')->get(),
            'roles' => User::ROLES,
        ]);
    }

    public function store(Request $request)
    {
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');
        $role = (string) $request->input('role', '');
        if ($username === '' || ! in_array($role, User::ROLES, true)) {
            return back()->with('error', 'Username and a valid role are required.')->withInput();
        }
        if (strlen($password) < 8) {
            return back()->with('error', 'Password must be at least 8 characters.')->withInput();
        }
        if (User::query()->where('username', $username)->exists()) {
            return back()->with('error', 'That username already exists.')->withInput();
        }

        $user = User::query()->create([
            'username' => $username,
            'password' => $password,
            'role' => $role,
        ]);
        $this->assign($user, $role, $request);

        return redirect()->route('users.index')->with('status', 'User created.');
    }

    public function update(Request $request, User $user)
    {
        $role = (string) $request->input('role', '');
        if (! in_array($role, User::ROLES, true)) {
            return back()->with('error', 'Invalid role.');
        }
        if ($user->isAdmin() && $role !== User::ADMIN && $this->adminCount() <= 1) {
            return back()->with('error', 'Cannot demote the last admin.');
        }

        $password = (string) $request->input('password', '');
        if ($password !== '') {
            if (strlen($password) < 8) {
                return back()->with('error', 'New password must be at least 8 characters.');
            }
            $user->password = $password;
        }
        $user->role = $role;
        $user->save();
        $this->assign($user, $role, $request);

        return redirect()->route('users.index')->with('status', 'User updated.');
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }
        if ($user->isAdmin() && $this->adminCount() <= 1) {
            return back()->with('error', 'Cannot delete the last admin.');
        }
        $user->delete();

        return redirect()->route('users.index')->with('status', 'User deleted.');
    }

    private function assign(User $user, string $role, Request $request): void
    {
        $ids = $role === User::ADMIN
            ? []
            : array_map('intval', (array) $request->input('devices', []));
        $valid = Device::query()->whereIn('id', $ids)->pluck('id')->all();
        $user->devices()->sync($valid);
    }

    private function adminCount(): int
    {
        return User::query()->where('role', User::ADMIN)->count();
    }
}

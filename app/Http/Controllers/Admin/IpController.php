<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiRequest;
use App\Models\IpRule;
use App\Models\LoginAttempt;
use Illuminate\Http\Request;

class IpController extends Controller
{
    public function index(Request $request)
    {
        $hours = max(1, min(24 * 30, (int) $request->query('hours', 24)));
        $cutoff = now()->subHours($hours);

        return view('ips.index', [
            'hours' => $hours,
            'rules' => IpRule::query()->orderByDesc('created_at')->limit(500)->get(),
            'logins' => LoginAttempt::query()->where('created_at', '>=', $cutoff)->orderByDesc('created_at')->limit(500)->get(),
            'requests' => ApiRequest::query()->where('created_at', '>=', $cutoff)->orderByDesc('created_at')->limit(500)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $ip = trim((string) $request->input('ip', ''));
        $kind = strtolower(trim((string) $request->input('kind', '')));
        $note = trim((string) $request->input('note', ''));
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return back()->with('error', 'Invalid IP address.')->withInput();
        }
        if (! in_array($kind, [IpRule::ALLOW, IpRule::DENY], true)) {
            return back()->with('error', "Kind must be 'allow' or 'deny'.")->withInput();
        }

        IpRule::query()->create([
            'ip' => $ip,
            'kind' => $kind,
            'source' => IpRule::MANUAL,
            'reason' => $note === '' ? null : mb_substr($note, 0, 255),
            'expires_at' => null,
            'created_by' => $request->user()->id,
        ]);

        $label = $kind === IpRule::ALLOW ? 'Whitelist' : 'Block';

        return redirect()->route('ips.index')->with('status', $label.' rule added.');
    }

    public function destroy(IpRule $rule)
    {
        $rule->delete();

        return redirect()->route('ips.index')->with('status', 'Rule removed.');
    }
}

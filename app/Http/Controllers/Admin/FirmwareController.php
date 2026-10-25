<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Firmware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FirmwareController extends Controller
{
    public function index()
    {
        return view('firmware.index', [
            'firmwares' => Firmware::query()->orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $version = trim((string) $request->input('version', ''));
        $channel = trim((string) $request->input('channel', Device::STABLE));
        $notes = trim((string) $request->input('notes', ''));
        if ($version === '') {
            return back()->with('error', 'Version is required.');
        }
        if (mb_strlen($version) > 64) {
            return back()->with('error', 'Version must be 64 characters or fewer.');
        }
        if (! in_array($channel, Device::CHANNELS, true)) {
            return back()->with('error', 'Invalid channel.');
        }
        $file = $request->file('file');
        if ($file === null || ! $file->isValid()) {
            return back()->with('error', 'Select a firmware .bin file.');
        }
        $max = (int) config('swimshady.firmware_max_bytes');
        if ($file->getSize() > $max) {
            return back()->with('error', 'Firmware file is too large.');
        }

        $bytes = file_get_contents($file->getRealPath());
        if ($bytes === false || $bytes === '') {
            return back()->with('error', 'Firmware file is empty.');
        }

        $firmware = Firmware::query()->create([
            'version' => $version,
            'channel' => $channel,
            'md5' => md5($bytes),
            'size' => strlen($bytes),
            'notes' => $notes === '' ? null : mb_substr($notes, 0, 255),
        ]);
        Storage::disk('local')->put($firmware->storagePath(), $bytes);

        return redirect()->route('firmware.index')->with('status', 'Firmware '.$version.' uploaded.');
    }

    public function destroy(Firmware $firmware)
    {
        $firmware->delete();

        return redirect()->route('firmware.index');
    }
}

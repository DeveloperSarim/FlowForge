<?php

namespace App\Http\Controllers;

use App\Domain\System\SystemSettings;
use App\Domain\System\Updater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Settings → Updates: get the update zip (from GitHub or uploaded in chunks), unpack it, then finish on the new code. */
class UpdateController extends Controller
{
    public function __construct(private Updater $updater) {}

    /** Is there a newer release on GitHub? `fresh` skips the 6-hour cache. */
    public function check(Request $request): JsonResponse
    {
        try {
            $latest = $this->updater->latest($request->boolean('fresh'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['current' => $this->updater->current(), 'latest' => $latest,
            'available' => ! empty($latest['zip']) && version_compare($latest['version'], $this->updater->current(), '>')]);
    }

    public function download(): JsonResponse
    {
        @set_time_limit(600);

        try {
            return response()->json(['package' => $this->updater->download()]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => ['required', 'regex:/^[a-zA-Z0-9-]{8,64}$/'],
            'index' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:1', 'max:500'],
            'offset' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file', 'max:4096'],
        ]);

        try {
            $package = $this->updater->receiveChunk($data['upload_id'], (int) $data['index'], (int) $data['total'], (int) $data['offset'], $request->file('chunk'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['package' => $package]);
    }

    public function extract(Request $request): JsonResponse
    {
        @set_time_limit(120);

        try {
            return response()->json($this->updater->extract((int) $request->validate(['from' => ['required', 'integer', 'min:0']])['from']));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function finish(SystemSettings $settings): JsonResponse
    {
        @set_time_limit(300);

        return response()->json($this->updater->finish($settings));
    }
}

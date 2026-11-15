<?php

declare(strict_types=1);

namespace Plugins\Modules\MobileAppApi\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Plugins\Modules\CourseManagement\Models\Course;
use Plugins\Modules\CourseManagement\Models\Enrollment;

class MobileApiController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:80'],
        ]);

        $user = User::query()->where('email', strtolower($validated['email']))->first();
        if (!$user || !$user->is_active || !Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'Invalid mobile credentials.'], 401);
        }

        $token = $user->createToken($validated['device_name'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'branch_id' => $user->branch_id,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $enrollments = Enrollment::query()
            ->where('user_id', $user->id)
            ->with('course:id,code,title,slug')
            ->get();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'branch_id' => $user->branch_id,
            ],
            'enrollments' => $enrollments,
        ]);
    }

    public function courses(): JsonResponse
    {
        return response()->json([
            'data' => Course::query()
                ->where('status', 'published')
                ->select(['id', 'code', 'title', 'slug', 'price'])
                ->latest()
                ->paginate(20),
        ]);
    }
}

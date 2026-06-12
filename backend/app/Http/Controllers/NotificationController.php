<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    // ── GET /api/notifications ────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate(20);

        return response()->json($notifications);
    }

    // ── GET /api/notifications/unread ─────────────────────────────────────
    public function unread(Request $request): JsonResponse
    {
        return response()->json([
            'count'         => $request->user()->unreadNotifications()->count(),
            'notifications' => $request->user()->unreadNotifications()->latest()->take(10)->get(),
        ]);
    }

    // ── PATCH /api/notifications/{id}/read ────────────────────────────────
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['message' => 'Notification marked as read.']);
    }

    // ── PATCH /api/notifications/read-all ────────────────────────────────
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}

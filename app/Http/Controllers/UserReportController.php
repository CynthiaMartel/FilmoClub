<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserReport;
use Illuminate\Http\Request;

class UserReportController extends Controller
{
    // POST /users/{user}/report
    // Rate-limit duro: 3 denuncias por día por usuario que denuncia.
    // Si el reporter tiene >70% de denuncias históricas descartadas → low_confidence = true.
    public function store(Request $request, User $reported)
    {
        $reporter = $request->user();

        if ($reporter->id === $reported->id) {
            return response()->json(['success' => 0, 'message' => 'No puedes denunciarte a ti mismo.'], 422);
        }

        $request->validate([
            'category' => 'required|in:spam,harassment,inappropriate_content,impersonation,other',
            'reason'   => 'nullable|string|max:500',
        ]);

        // Rate-limit: máximo 3 denuncias por día
        $todayCount = UserReport::where('reporter_id', $reporter->id)
            ->whereDate('created_at', today())
            ->count();

        if ($todayCount >= 3) {
            return response()->json([
                'success' => 0,
                'message' => 'Has alcanzado el límite de 3 denuncias diarias. Inténtalo mañana.',
            ], 429);
        }

        // Evitar denuncia duplicada del mismo día contra el mismo usuario
        $alreadyToday = UserReport::where('reporter_id', $reporter->id)
            ->where('reported_user_id', $reported->id)
            ->whereDate('created_at', today())
            ->exists();

        if ($alreadyToday) {
            return response()->json([
                'success' => 0,
                'message' => 'Ya has denunciado a este usuario hoy.',
            ], 422);
        }

        // Calcular si el reporter tiene historial de denuncias falsas (>70% dismissed)
        $historical = UserReport::where('reporter_id', $reporter->id)
            ->whereIn('status', ['dismissed', 'actioned', 'reviewed'])
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status = "dismissed" THEN 1 ELSE 0 END) as dismissed')
            ->first();

        $lowConfidence = false;
        if ($historical && $historical->total >= 5) {
            $dismissedRate = $historical->dismissed / $historical->total;
            $lowConfidence = $dismissedRate > 0.70;
        }

        $report = UserReport::create([
            'reporter_id'      => $reporter->id,
            'reported_user_id' => $reported->id,
            'category'         => $request->category,
            'reason'           => self::sanitizeText($request->reason),
            'status'           => 'pending',
            'low_confidence'   => $lowConfidence,
        ]);

        return response()->json([
            'success' => 1,
            'message' => 'Denuncia enviada. El equipo de moderación la revisará.',
        ], 201);
    }

    // Elimina HTML, caracteres de control y Unicode peligroso del texto libre.
    // No lanzamos error si el usuario escribe HTML — lo quitamos silenciosamente.
    private static function sanitizeText(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        // 1. Quitar etiquetas HTML y entidades peligrosas
        $text = strip_tags($text);

        // 2. Quitar caracteres de control ASCII (null bytes, BEL, etc.)
        //    Conservamos \t (tab), \n y \r para saltos de línea normales
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        // 3. Quitar caracteres Unicode peligrosos:
        //    - RTL/LTR overrides (U+202A–U+202E) que invierten texto visualmente
        //    - Zero-width chars (U+200B–U+200D, U+FEFF) que ocultan contenido
        //    - Soft hyphen (U+00AD) usado para evadir filtros de texto
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{202A}-\x{202E}\x{00AD}]/u', '', $text);

        // 4. Normalizar saltos de línea y espacios múltiples
        $text = preg_replace('/\r\n|\r/', "\n", $text);   // unificar \r\n → \n
        $text = preg_replace('/[ \t]+/', ' ', $text);      // espacios/tabs múltiples → uno
        $text = preg_replace('/\n{3,}/', "\n\n", $text);   // más de 2 saltos → máximo 2
        $text = trim($text);

        return $text !== '' ? $text : null;
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\FarmVerification;
use App\Models\FarmVerificationDocument;
use App\Services\Farms\FarmVerificationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FarmVerificationDocumentController extends Controller
{
    public function show(Request $request, FarmVerification $verification, FarmVerificationDocument $document, FarmVerificationService $verifications): Response
    {
        $contents = $verifications->readDocument($document, $request->user());

        return response($contents, 200, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => 'inline; filename="'.addcslashes($document->original_name, '"\\').'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

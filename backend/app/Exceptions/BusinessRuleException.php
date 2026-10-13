<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request that is valid in shape but not allowed in the current state,
 * e.g. submitting verification for a farm that is already approved.
 */
class BusinessRuleException extends Exception
{
    /**
     * @param  array<string, mixed>  $details  Extra fields for the client, e.g. the problems to fix.
     */
    public function __construct(string $message, private int $status = Response::HTTP_CONFLICT, private array $details = [])
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), ...$this->details], $this->status);
    }
}

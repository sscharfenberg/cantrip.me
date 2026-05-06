<?php

namespace App\Http\Requests\Decks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorises the deck CSV import page (GET). Any authenticated user
 * can reach the page — both source paths mint a fresh deck on POST,
 * so there's no per-deck authorisation to enforce here.
 */
class ShowDeckImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}

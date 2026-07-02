<?php

namespace App\Http\Controllers;

use App\Models\FeedFile;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class PublicFeedController extends Controller
{
    public function varle(): Response
    {
        $relativePath = FeedFile::query()
            ->whereHas('marketplaceChannel', fn ($query) => $query->where('type', 'varle'))
            ->latest('generated_at')
            ->value('path')
            ?? config('marketplace.exports.varle.feed_path', 'feeds/varle.xml');

        if (! Storage::disk('public')->exists($relativePath)) {
            abort(404, 'Varle feed not found.');
        }

        return response(
            Storage::disk('public')->get($relativePath),
            200,
            ['Content-Type' => 'application/xml; charset=UTF-8'],
        );
    }
}

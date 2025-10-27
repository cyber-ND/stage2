<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Country;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver; // ✅ for ImageManager::gd() syntax (v3+)
use Carbon\Carbon;

class CountryController extends Controller
{
    /* -------------------------------------------------------------
       POST /countries/refresh
       ------------------------------------------------------------- */
    public function refresh()
    {
        try {
            // Fetch countries
            $countriesResponse = Http::timeout(10)->get(
                'https://restcountries.com/v2/all',
                ['fields' => 'name,capital,region,population,flag,currencies']
            );

            if ($countriesResponse->failed()) {
                return response()->json([
                    'error'   => 'External data source unavailable',
                    'details' => 'Could not fetch data from restcountries.com',
                ], 503);
            }

            $countries = $countriesResponse->json();

            // Fetch exchange rates
            $exchangeResponse = Http::timeout(10)->get('https://open.er-api.com/v6/latest/USD');
            if ($exchangeResponse->failed()) {
                return response()->json([
                    'error'   => 'External data source unavailable',
                    'details' => 'Could not fetch data from open.er-api.com',
                ], 503);
            }

            $rates = $exchangeResponse->json()['rates'] ?? [];

            $now          = now();
            $savedCount   = 0;
            $countriesToSave = [];

            foreach ($countries as $countryData) {
                $name        = $countryData['name'] ?? null;
                $population  = $countryData['population'] ?? null;
                if (!$name || !$population) {
                    continue;
                }

                $currency     = $countryData['currencies'][0] ?? null;
                $currencyCode = $currency['code'] ?? null;

                $exchangeRate = null;
                $estimatedGdp = null;

                if ($currencyCode && isset($rates[$currencyCode])) {
                    $exchangeRate = $rates[$currencyCode];
                    $multiplier   = rand(1000, 2000);
                    $estimatedGdp = ($population * $multiplier) / $exchangeRate;
                }

                $countriesToSave[] = [
                    'name'              => $name,
                    'capital'           => $countryData['capital'] ?? null,
                    'region'            => $countryData['region'] ?? null,
                    'population'        => $population,
                    'currency_code'     => $currencyCode,
                    'exchange_rate'     => $exchangeRate,
                    'estimated_gdp'     => $estimatedGdp,
                    'flag_url'          => $countryData['flag'] ?? null,
                    'last_refreshed_at' => $now,
                ];

                $savedCount++;
            }

            // ✅ Batch upsert (100 rows per query)
            collect($countriesToSave)->chunk(100)->each(function ($chunk) {
                Country::upsert(
                    $chunk->toArray(),
                    ['name'],
                    [
                        'capital', 'region', 'population', 'currency_code',
                        'exchange_rate', 'estimated_gdp', 'flag_url', 'last_refreshed_at'
                    ]
                );
            });

            // ✅ Generate summary image
            $this->generateSummaryImage();

            return response()->json([
                'message'           => 'Countries refreshed successfully',
                'total_countries'   => $savedCount,
                'last_refreshed_at' => $now->toIso8601String(),
            ], 200);

        } catch (\Throwable $e) { // ✅ Throwable catches both Exception & Error
            Log::error('Refresh failed: ' . $e->getMessage());
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    /* -------------------------------------------------------------
       GET /countries  (filters + sorting) – plain array
       ------------------------------------------------------------- */
    public function index(Request $request)
    {
        $query = Country::query();

        if ($request->filled('region')) {
            $query->where('region', $request->region);
        }

        if ($request->filled('currency')) {
            $query->where('currency_code', $request->currency);
        }

        if ($request->input('sort') === 'gdp_desc') {
            // ✅ safer and SQL-compatible
            $query->orderByDesc('estimated_gdp');
        }

        $countries = $query->get();

        return response()->json($countries);
    }

    /* -------------------------------------------------------------
       GET /countries/{name}
       ------------------------------------------------------------- */
    public function show($name)
    {
        $country = Country::where('name', 'LIKE', "%{$name}%")->first();

        if (!$country) {
            return response()->json(['error' => 'Country not found'], 404);
        }

        return response()->json($country);
    }

    /* -------------------------------------------------------------
       DELETE /countries/{name}
       ------------------------------------------------------------- */
    public function destroy($name)
    {
        $country = Country::where('name', 'LIKE', "%{$name}%")->first();

        if (!$country) {
            return response()->json(['error' => 'Country not found'], 404);
        }

        $country->delete();

        return response()->json(['message' => 'Country deleted successfully']);
    }

    /* -------------------------------------------------------------
       GET /status
       ------------------------------------------------------------- */
    public function status()
    {
        $total     = Country::count();
        $last      = Country::max('last_refreshed_at');
        $formatted = $last ? Carbon::parse($last)->toIso8601String() : null;

        return response()->json([
            'total_countries'   => $total,
            'last_refreshed_at' => $formatted,
        ]);
    }

    /* -------------------------------------------------------------
       GET /countries/image
       ------------------------------------------------------------- */
    public function image()
    {
        $path = public_path('storage/summary.png');

        if (!file_exists($path)) {
            return response()->json([
                'error' => 'Summary image not found',
                'hint'  => 'Run POST /countries/refresh first to generate the image',
            ], 404);
        }

        return response()->file($path);
    }

    /* -------------------------------------------------------------
       PRIVATE: generate summary image
       ------------------------------------------------------------- */
    private function generateSummaryImage()
    {
        $total = Country::count();
        $top5  = Country::orderByDesc('estimated_gdp')->take(5)->get();

        $timestamp = now()->format('Y-m-d H:i:s T');

        // ✅ Updated for Intervention Image v3 syntax
        $manager = new ImageManager(new Driver());
        $img = $manager->create(800, 600)->fill('#1a1a1a');

        // Title
        $img->text('Country GDP Summary', 400, 50, function ($font) {
            $font->size(36)->color('#ffffff')->align('center')->valign('top');
        });

        // Total
        $img->text("Total Countries: " . Country::count(), 400, 120, function ($font) {
            $font->size(28)->color('#4ade80')->align('center');
        });

        // Header
        $img->text('Top 5 by Estimated GDP', 400, 180, function ($font) {
            $font->size(24)->color('#60a5fa')->align('center');
        });

        // Top 5 list
        foreach ($top5 as $index => $country) {
            $gdp  = $country->estimated_gdp
                ? number_format($country->estimated_gdp / 1_000_000_000, 2) . 'B'
                : 'N/A';
            $name = Str::limit($country->name, 20);
            $text = sprintf('%d. %s — $%s', $index + 1, $name, $gdp);

            $img->text($text, 400, 230 + ($index * 40), function ($font) {
                $font->size(20)->color('#e5e7eb')->align('center');
            });
        }

        // Timestamp
        $img->text("Generated: {$timestamp}", 400, 550, function ($font) {
            $font->size(18)->color('#9ca3af')->align('center');
        });

        $path = public_path('storage/summary.png');
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $img->save($path);
    }
}

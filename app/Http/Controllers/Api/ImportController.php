<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SenderMailbox;
use App\Models\SeedMailbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ImportController extends Controller
{
    public function importSenders(Request $request): JsonResponse
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        return $this->processCsv($request->file('csv_file'), 'sender');
    }

    public function importSeeds(Request $request): JsonResponse
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:2048',
        ]);

        return $this->processCsv($request->file('csv_file'), 'seed');
    }

    private function processCsv($file, string $type): JsonResponse
    {
        $handle = fopen($file->getRealPath(), 'r');
        if (!$handle) {
            return response()->json(['error' => 'Could not read file'], 422);
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return response()->json(['error' => 'Empty CSV file'], 422);
        }

        $header = array_map(fn($h) => strtolower(trim($h)), $header);

        $requiredFields = ['email_address', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password'];
        if ($type === 'seed') {
            $requiredFields = array_merge($requiredFields, ['imap_host', 'imap_port', 'imap_username', 'imap_password']);
        }

        $missing = array_diff($requiredFields, $header);
        if (!empty($missing)) {
            fclose($handle);
            return response()->json([
                'error' => 'Missing required columns: ' . implode(', ', $missing),
                'required' => $requiredFields,
            ], 422);
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $row = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $row++;
            if (count($data) !== count($header)) {
                $errors[] = "Row {$row}: column count mismatch";
                $skipped++;
                continue;
            }

            $record = array_combine($header, $data);
            $email = trim($record['email_address'] ?? '');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Row {$row}: invalid email '{$email}'";
                $skipped++;
                continue;
            }

            $model = $type === 'sender' ? SenderMailbox::class : SeedMailbox::class;

            if ($model::where('email_address', $email)->exists()) {
                $errors[] = "Row {$row}: '{$email}' already exists";
                $skipped++;
                continue;
            }

            $fields = [
                'email_address' => $email,
                'smtp_host' => trim($record['smtp_host']),
                'smtp_port' => (int) trim($record['smtp_port']),
                'smtp_username' => trim($record['smtp_username']),
                'smtp_password' => trim($record['smtp_password']),
                'smtp_encryption' => trim($record['smtp_encryption'] ?? 'tls'),
                'provider' => trim($record['provider'] ?? ''),
                'status' => 'active',
            ];

            if ($type === 'seed') {
                $fields['imap_host'] = trim($record['imap_host']);
                $fields['imap_port'] = (int) trim($record['imap_port']);
                $fields['imap_username'] = trim($record['imap_username']);
                $fields['imap_password'] = trim($record['imap_password']);
                $fields['imap_encryption'] = trim($record['imap_encryption'] ?? 'ssl');
            }

            if (isset($record['warmup_target_daily'])) {
                $fields['warmup_target_daily'] = (int) trim($record['warmup_target_daily']);
            }

            try {
                $model::create($fields);
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Row {$row}: " . $e->getMessage();
                $skipped++;
            }
        }

        fclose($handle);

        return response()->json([
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 20),
        ]);
    }
}

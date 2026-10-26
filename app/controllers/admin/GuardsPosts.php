<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\ImageUpload;

/**
 * Shared checks for admin form submissions.
 */
trait GuardsPosts
{
    /**
     * Reject oversized requests and requests without a valid CSRF token.
     * When a POST exceeds post_max_size PHP silently empties $_POST and
     * $_FILES, which would otherwise look like a CSRF failure.
     */
    private static function guardPost(string $backUrl): void
    {
        if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            flash('error', sprintf(
                'The upload was too large. Maximum image size is %s.',
                ImageUpload::formatBytes(ImageUpload::maxBytes())
            ));
            redirect($backUrl);
        }

        if (!csrf_valid()) {
            flash('error', 'Your session expired or the form was out of date. Please try again.');
            redirect($backUrl);
        }
    }

    /** Positive integer id from a route segment, or null. */
    private static function routeId(string $id): ?int
    {
        return ctype_digit($id) && (int) $id > 0 && strlen($id) < 11 ? (int) $id : null;
    }

    /** Reject text that isn't valid UTF-8 (regexes with /u would silently fail). */
    private static function invalidEncoding(array $fields): array
    {
        $errors = [];
        foreach ($fields as $field) {
            if (isset($_POST[$field]) && (!is_string($_POST[$field]) || !mb_check_encoding($_POST[$field], 'UTF-8'))) {
                $errors[$field] = 'This field contains invalid characters.';
                $_POST[$field] = '';
            }
        }
        return $errors;
    }
}

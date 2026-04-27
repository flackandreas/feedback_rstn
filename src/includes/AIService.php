<?php

namespace App\Includes;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Dotenv\Dotenv;

class AIService {
    private $client;
    private $apiKey;

    public function __construct() {
        // Lade .env falls vorhanden (für lokale Entwicklung)
        $envPath = __DIR__ . '/../';
        if (file_exists($envPath . '.env')) {
            $dotenv = Dotenv::createImmutable($envPath);
            $dotenv->load();
        }

        $this->apiKey = $_ENV['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY');

        if (!$this->apiKey) {
            throw new \Exception("GEMINI_API_KEY is not set in environment.");
        }

        $this->client = new Client([
            'base_uri' => 'https://generativelanguage.googleapis.com/',
            'timeout'  => 60.0,
        ]);
    }

    public function evaluateHomeworkImage(string $taskDescription, string $imagePath, string $studentPseudonym): array {
        // Read image and encode to base64
        if (!file_exists($imagePath)) {
            throw new \Exception("Image not found: " . $imagePath);
        }

        $mimeType = mime_content_type($imagePath);
        $imageData = base64_encode(file_get_contents($imagePath));

        $prompt = "Du bist ein erfahrener und ermutigender Lehrer. \n" .
                  "Die Aufgabe lautet: " . $taskDescription . "\n\n" .
                  "Hier ist die eingereichte Hausaufgabe (als Bild) von Schüler-ID " . $studentPseudonym . ". \n" .
                  "Werte diese Hausaufgabe aus und antworte AUSSCHLIESSLICH im JSON-Format mit exakt folgenden Feldern:\n" .
                  "{\n" .
                  "  \"student_feedback\": \"Dein konstruktives, motivierendes Feedback für den Schüler in der Du-Form.\",\n" .
                  "  \"teacher_notes\": \"Kurze, stichpunktartige Liste der fachlichen oder konzeptionellen Fehler für die Lehrkraft zur Auswertung.\",\n" .
                  "  \"score\": 85,\n" .
                  "  \"errors\": [\n" .
                  "    {\n" .
                  "      \"description\": \"Kurze, ermutigende Erklärung, was hier falsch berechnet/geschrieben wurde.\",\n" .
                  "      \"box_2d\": [ymin, xmin, ymax, xmax] // Relativierte Koordinaten von 0 bis 1000 für die Bounding Box des Fehlers im Bild. Z.B. [450, 200, 500, 400]\n" .
                  "    }\n" .
                  "  ]\n" .
                  "}";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => $imageData
                            ]
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.4,
                'responseMimeType' => 'application/json'
            ]
        ];

        try {
            $response = $this->client->post('v1beta/models/gemini-2.5-flash:generateContent?key=' . $this->apiKey, [
                'json' => $payload
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (isset($body['candidates'][0]['content']['parts'][0]['text'])) {
                $responseText = $body['candidates'][0]['content']['parts'][0]['text'];
                // Clean markdown JSON wrapper if present
                $responseText = trim(preg_replace('/^```json|```$/m', '', $responseText));
                $result = json_decode($responseText, true);
                
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $result;
                } else {
                    throw new \Exception("Invalid JSON response from Gemini API: " . json_last_error_msg());
                }
            } else {
                throw new \Exception("Unexpected response format from Gemini API.");
            }

        } catch (RequestException $e) {
            throw new \Exception("API Request failed: " . $e->getMessage());
        }
    }
}

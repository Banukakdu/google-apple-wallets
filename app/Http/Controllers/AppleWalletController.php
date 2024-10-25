<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
class AppleWalletController extends Controller
{

    public function generatePass(Request $request)
    {
        // Validate the request input
        $data = $request->validate([
            'username' => 'required|string',
            'points' => 'required|integer',
            'serialNumber' => 'required|string',  // Removed the unique check since we're not using DB
        ]);

        // Create the pass directory if not exists
        $passDir = storage_path("app/passes/{$data['serialNumber']}");
        if (!file_exists($passDir)) {
            mkdir($passDir, 0755, true);
        }

        // Copy images to the pass directory
        $imageDir = storage_path('app/pass_images');
        copy("$imageDir/logo.png", "$passDir/logo.png");
        copy("$imageDir/icon.png", "$passDir/icon.png");
        copy("$imageDir/icon@2x.png", "$passDir/icon@2x.png");
        copy("$imageDir/strip.png", "$passDir/strip.png");

        // Create pass.json with the user's details
        $passJson = [
            "formatVersion" => 1,
            "passTypeIdentifier" => "pass.com.loyalty.company",  // Make sure this is registered in Apple Developer Portal
            "serialNumber" => $data['serialNumber'],
            "teamIdentifier" => "J8LVCC2449",  // Your Apple Developer Team ID
            "labelColor" => "#0693E3",
            "organizationName" => "Flexiata",
            "logoText" => "Beacon Lighting",
            "description" => "This is your Flexiata loyalty card.",  // Add description here
            "foregroundColor" => "#000000",
            "backgroundColor" => "rgba(255,218,0,255)",
            "storeCard" => [
                "primaryFields" => [
                    [
                        "key" => "balance",
                        "label" => "Points Balance",
                        "value" => "{$data['points']} Points"
                    ]
                ],
                "secondaryFields" => [
                    [
                        "key" => "username",
                        "label" => "Name",
                        "value" => $data['username']
                    ]
                ]
            ],
            "barcode" => [
                "format" => "PKBarcodeFormatQR",
                "message" => $data['serialNumber'],  // QR code will display the serial number
                "messageEncoding" => "iso-8859-1",
                "altText" => $data['serialNumber']
            ]
        ];


        // Save pass.json
        file_put_contents("$passDir/pass.json", json_encode($passJson, JSON_PRETTY_PRINT));

        // Call methods to generate manifest, sign pass, and package pass
        $this->generateManifest($passDir);
        $this->signPass($passDir);
        $this->packagePass($passDir, "$passDir/{$data['serialNumber']}.pkpass");

        // Return the download URL for testing purposes
        return response()->json([
            'message' => 'Pass created successfully',
            'download_url' => url("/api/download-pass/{$data['serialNumber']}")
        ]);
    }


    // Generate manifest.json
    private function generateManifest($passDir)
    {
        $files = ['pass.json', 'logo.png', 'icon.png', 'icon@2x.png', 'strip.png'];
        $manifest = [];

        foreach ($files as $file) {
            $path = "$passDir/$file";
            if (file_exists($path)) {
                $manifest[$file] = sha1_file($path);
            }
        }

        file_put_contents("$passDir/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT));
    }

    // Sign pass using OpenSSL
    private function signPass($passDir)
    {
        $pemCertPath = storage_path('app/certificates/passkey.pem');   // Path to PEM certificate
        $wwdrCertPath = storage_path('app/certificates/AppleWWDRCA.pem');  // Path to WWDR certificate
        $manifestPath = "$passDir/manifest.json";
        $signaturePath = "$passDir/signature";   // Output signature path

        // Ensure the necessary files exist
        if (!file_exists($pemCertPath)) {
            throw new \Exception("PEM certificate file not found: $pemCertPath");
        }
        if (!file_exists($wwdrCertPath)) {
            throw new \Exception("WWDR certificate file not found: $wwdrCertPath");
        }
        if (!file_exists($manifestPath)) {
            throw new \Exception("Manifest file not found: $manifestPath");
        }

        // OpenSSL command to generate the signature
        $command = "openssl smime -binary -sign -certfile $wwdrCertPath -signer $pemCertPath -inkey $pemCertPath -in $manifestPath -outform DER -out $signaturePath";

        // Execute the OpenSSL command and capture output
        $output = shell_exec($command . ' 2>&1');  // Capture stderr as well

        // Check if the signature file was created
        if (!file_exists($signaturePath)) {
            // Print the output for debugging
            throw new \Exception("Failed to generate signature: $signaturePath. OpenSSL output: " . $output);
        }
    }


    // Package pass into .pkpass file
    private function packagePass($passDir, $outputPath)
    {
        $zip = new \ZipArchive();

        // Open or create the ZIP file
        if ($zip->open($outputPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
            $files = ['pass.json', 'manifest.json', 'signature', 'logo.png', 'icon.png', 'icon@2x.png', 'strip.png'];

            foreach ($files as $file) {
                $filePath = "$passDir/$file";

                // Check if the file exists before adding it to the zip archive
                if (file_exists($filePath)) {
                    $zip->addFile($filePath, $file);
                } else {
                    throw new \Exception("File not found: $filePath");
                }
            }

            // Close the zip file
            $zip->close();
        } else {
            throw new \Exception("Could not create ZIP archive: $outputPath");
        }
    }
    public function updatePass(Request $request)
    {
        // Validate input
        $data = $request->validate([
            'serialNumber' => 'required|string|exists:passes',
            'points' => 'required|integer'
        ]);

        // Load existing pass.json
        $passDir = storage_path("app/passes/{$data['serialNumber']}");
        $passJson = json_decode(file_get_contents("$passDir/pass.json"), true);

        // Update points in pass.json
        $passJson['storeCard']['primaryFields'][0]['value'] = "{$data['points']} Points";

        // Save updated pass.json
        file_put_contents("$passDir/pass.json", json_encode($passJson, JSON_PRETTY_PRINT));

        // Regenerate manifest and re-sign the pass
        $this->generateManifest($passDir);
        $this->signPass($passDir);

        return response()->json([
            'message' => 'Pass updated successfully',
            'download_url' => url("/api/download-pass/{$data['serialNumber']}")
        ]);
    }

    public function downloadPass($serialNumber)
    {
        $pathToPkPass = storage_path("app/passes/{$serialNumber}/{$serialNumber}.pkpass");

        if (!file_exists($pathToPkPass)) {
            return response()->json(['error' => 'Pass not found'], 404);
        }

        return response()->download($pathToPkPass, "{$serialNumber}.pkpass", [
            'Content-Type' => 'application/vnd.apple.pkpass',
            'Content-Disposition' => 'attachment; filename="' . $serialNumber . '.pkpass"',
        ]);
    }


}

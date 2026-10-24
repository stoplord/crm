<?php
/**
 * CRM SYSTEM - FACEBOOK LEAD ADS WEBHOOK RECEIVER
 * Public: public/api/fb_webhook.php
 * Captures Meta Lead Ads data and inserts it into the existing clients table.
 * No database structure changes required.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/config.php';

// Define your custom verify token set in the Meta Developer Dashboard
$VERIFY_TOKEN = "my_secure_fb_token_123"; 

// YOUR FACEBOOK PAGE ACCESS TOKEN (Generated from Meta Graph API Explorer or App Settings)
$pageAccessToken = "EAANnaXQPxGsBSdMNLVUZCZAo1hzj4tD6jFtvJZCxEIMhfO0BqvMdG0kqoDNbpcCZAwnZB7UwXAJV6GClRRP6qWF2tuX0l7ehaf5hhwKMkXMcSyHuk9w7DU0QIVAvBpMZC3hJflFmcaZB0p7Oicz2BoEMzFI8AGS8RUPWRtnsGoQLW1saItQljSlzPljBS15fO1JUYZCdF9TZC";

/**
 * 1. HANDLE WEBHOOK VERIFICATION (GET request from Meta)
 * When you click "Verify and Save" in Meta dashboard, Facebook sends a GET request.
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    if ($mode === 'subscribe' && $token === $VERIFY_TOKEN) {
        echo $challenge;
        exit;
    } else {
        http_response_code(403);
        exit;
    }
}

/**
 * 2. HANDLE INCOMING LEAD DATA (POST request from Meta)
 * Triggered automatically when a user submits a lead form on Facebook or Instagram.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    // Check if the payload is a leadgen event
    if (isset($data['object']) && $data['object'] === 'page') {
        foreach ($data['entry'] as $entry) {
            foreach ($entry['changes'] as $change) {
                if ($change['field'] === 'leadgen') {
                    $lead_id = $change['value']['leadgen_id'];
                    
                    // Fetch actual lead details from Facebook Graph API using the lead ID
                    $graphUrl = "https://graph.facebook.com/v18.0/{$lead_id}?access_token=" . urlencode($pageAccessToken);
                    
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $graphUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $response = curl_exec($ch);
                    curl_close($ch);
                    
                    $leadData = json_decode($response, true);
                    
                    if (isset($leadData['field_data'])) {
                        $name = '';
                        $phone = '';
                        $email = '';
                        $state = '';

                        // Extract field data mapped from your Facebook Instant Form fields
                        foreach ($leadData['field_data'] as $field) {
                            $fieldName = $field['name'];
                            $fieldValue = $field['values'][0] ?? '';

                            if ($fieldName === 'full_name' || $fieldName === 'name') {
                                $name = htmlspecialchars(trim($fieldValue), ENT_QUOTES);
                            }
                            if ($fieldName === 'phone_number' || $fieldName === 'phone') {
                                // Clean phone number formatting
                                $phone = preg_replace('/[^0-9]/', '', $fieldValue);
                                if (strlen($phone) > 10) {
                                    $phone = substr($phone, -10); // Retain standard 10-digit mobile format
                                }
                            }
                            if ($fieldName === 'email') {
                                $email = htmlspecialchars(trim($fieldValue), ENT_QUOTES);
                            }
                            if ($fieldName === 'state') {
                                $state = htmlspecialchars(trim($fieldValue), ENT_QUOTES);
                            }
                        }

                        // Ensure mandatory fields (Name and Mobile) are present before saving
                        if (!empty($name) && !empty($phone)) {
                            try {
                                $pdo->beginTransaction();

                                // Generate Unique Client Code matching your CRM convention (e.g., CL-000012)
                                $stmtMax = $pdo->query("SELECT MAX(id) FROM clients");
                                $maxId = $stmtMax->fetchColumn() ?: 0;
                                $client_code = 'CL-' . str_pad($maxId + 1, 6, '0', STR_PAD_LEFT);

                                // Default assigned admin ID (e.g., ID 1 or Super Admin / Lead Distribution Admin)
                                $default_admin_id = 1; 

                                // Insert directly into your existing clients table
                                $stmt = $pdo->prepare("
                                    INSERT INTO clients 
                                    (client_code, name, phone, email, state, temperature, status, deal_status, assigned_admin_id, created_by, requirement) 
                                    VALUES (?, ?, ?, ?, ?, 'HOT', 'New', 'Open', ?, ?, 'Generated via Facebook Meta Lead Ads')
                                ");
                                $stmt->execute([$client_code, $name, $phone, $email, $state, $default_admin_id, $default_admin_id]);
                                
                                $new_client_id = $pdo->lastInsertId();

                                // Log the automatic capture in your client activities timeline
                                $actStmt = $pdo->prepare("
                                    INSERT INTO client_activities (client_id, admin_id, activity_type, activity_date, activity_time, notes) 
                                    VALUES (?, ?, 'Other', CURDATE(), CURTIME(), 'Lead automatically captured and synchronized from Facebook Meta Ads.')
                                ");
                                $actStmt->execute([$new_client_id, $default_admin_id]);

                                // Record an audit log entry
                                $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, role, action, target_type, target_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                $audit->execute([
                                    $default_admin_id, 'super_admin', 'facebook_lead_sync', 'client', $new_client_id,
                                    "Synced new Facebook Lead: {$name} ({$phone})", $_SERVER['REMOTE_ADDR']
                                ]);

                                $pdo->commit();

                            } catch (Exception $e) {
                                if ($pdo->inTransaction()) $pdo->rollBack();
                                // Silent fail response or log error internally to file
                                error_log("FB Lead Sync Error: " . $e->getMessage());
                            }
                        }
                    }
                }
            }
        }
        // Always return a 200 OK response quickly to Meta so it knows the webhook was received successfully
        http_response_code(200);
        echo json_encode(['status' => 'success']);
        exit;
    }
}
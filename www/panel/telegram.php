<?php
function sendTelegram($message) {
    $token = '';
    $chat  = '';
    
    // Log inicial
    error_log("Telegram: Intentando enviar mensaje");
    error_log("Telegram: Chat ID = " . $chat);
    
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    
    $data = [
        'chat_id' => $chat,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ];
    
    $opts = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
            'timeout' => 10,
            'ignore_errors' => true
        ]
    ];
    
    try {
        $context = stream_context_create($opts);
        $result = @file_get_contents($url, false, $context);
        
        error_log("Telegram: Resultado raw = " . substr($result, 0, 200));
        
        // Verificar si la respuesta HTTP fue exitosa
        if ($result === false) {
            error_log("Telegram: ERROR - No se pudo conectar a la API");
            error_log("Telegram: Error detalle = " . print_r(error_get_last(), true));
            return false;
        }
        
        // Decodificar respuesta de Telegram
        $response = json_decode($result, true);
        
        if (!$response) {
            error_log("Telegram: ERROR - Respuesta inválida (no es JSON válido)");
            return false;
        }
        
        error_log("Telegram: Respuesta decodificada = " . print_r($response, true));
        
        if (!isset($response['ok']) || !$response['ok']) {
            $errorDesc = $response['description'] ?? 'desconocido';
            error_log("Telegram: ERROR - API respondió con error: " . $errorDesc);
            return false;
        }
        
        // Todo OK
        error_log("Telegram: ✓ Mensaje enviado exitosamente");
        return true;
        
    } catch (Exception $e) {
        error_log("Telegram: EXCEPCIÓN - " . $e->getMessage());
        error_log("Telegram: Trace = " . $e->getTraceAsString());
        return false;
    }
}

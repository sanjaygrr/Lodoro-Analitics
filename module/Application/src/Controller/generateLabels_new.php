    /**
     * Genera lista de enlaces a etiquetas para órdenes
     *
     * @param array $orderIds IDs de las órdenes
     * @param string $table Tabla de órdenes (marketplace)
     * @return Response
     */
    private function generateLabels(array $orderIds, $table = null)
    {
        // Determinar la tabla a usar o aplicarlo para todas
        $tables = [];
        if ($table && $table !== 'all') {
            $tables[] = $table;
        } else {
            $tables = [
                'Orders_WALLMART',
                'Orders_RIPLEY',
                'Orders_FALABELLA',
                'Orders_MERCADO_LIBRE',
                'Orders_PARIS',
                'Orders_WOOCOMMERCE'
            ];
        }
        
        // Recolectar URLs de etiquetas
        $labelUrls = [];
        
        foreach ($tables as $currentTable) {
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $sql = "SELECT id, label_url FROM `$currentTable` WHERE id IN ($placeholders)";
            $statement = $this->dbAdapter->createStatement($sql);
            $result = $statement->execute($orderIds);
            
            foreach ($result as $row) {
                if (!empty($row['label_url']) && filter_var($row['label_url'], FILTER_VALIDATE_URL)) {
                    $labelUrls[$row['id']] = $row['label_url'];
                    
                    // Marcar como impresa
                    $this->databaseService->execute(
                        "UPDATE `$currentTable` SET printed = 1 WHERE id = ?",
                        [$row['id']]
                    );
                }
            }
        }
        
        // Si no hay URLs de etiquetas, mostrar mensaje
        if (empty($labelUrls)) {
            // Crear una página HTML simple indicando que no hay etiquetas
            $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>No hay etiquetas disponibles</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; text-align: center; }
        .message { color: #d9534f; font-size: 24px; margin: 40px 0; }
    </style>
</head>
<body>
    <div class="message">No se encontraron etiquetas disponibles</div>
    <p>Las órdenes seleccionadas no tienen URLs de etiquetas asociadas.</p>
</body>
</html>';
        } else {
            // Crear una página HTML con links directos a las etiquetas
            $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Etiquetas</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        h1 { color: #333; }
        .etiqueta-container { margin-bottom: 20px; }
        .etiqueta-link { display: block; padding: 10px; background: #f5f5f5; margin: 10px 0; 
                       text-decoration: none; color: #333; border-radius: 4px; }
        .etiqueta-link:hover { background: #e0e0e0; }
    </style>
</head>
<body>
    <h1>Etiquetas de Envío</h1>
    <p>Haga clic en los enlaces a continuación para ver cada etiqueta:</p>';
            
            // Agregar un link para cada URL de etiqueta
            foreach ($labelUrls as $orderId => $url) {
                $html .= '
<div class="etiqueta-container">
    <strong>Orden #' . $orderId . '</strong>
    <a href="' . $url . '" class="etiqueta-link" target="_blank">Ver Etiqueta</a>
</div>';
            }
            
            $html .= '
</body>
</html>';
        }

        // Configurar respuesta HTTP
        $response = new Response();
        $response->setContent($html);
        
        // Configurar cabeceras
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'text/html; charset=UTF-8');
        
        return $response;
    }
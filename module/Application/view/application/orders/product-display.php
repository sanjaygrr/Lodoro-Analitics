<?php
// Archivo separado para la lógica de mostrar productos
// Simplificado para evitar duplicación y mejorar estética

// Mostrar productos - versión simplificada
// Prioridad: 1. productos_bsale/product_names, 2. product_list, 3. productos, 4. sku/skus

$productosMostrar = [];
$productosYaMostrados = []; // Para evitar duplicados

// 1. Primero intentar con productos_bsale o product_names (datos más limpios)
if (!empty($order['productos_bsale']) || !empty($order['product_names']) || !empty($order['product_list'])) {
    $productNames = [];
    $skus = [];
    
    // Obtener nombres de productos
    if (!empty($order['productos_bsale'])) {
        $productNames = explode(' | ', $order['productos_bsale']);
        if (!empty($order['skus_bsale'])) {
            $skus = explode(', ', $order['skus_bsale']);
        }
    } elseif (!empty($order['product_names'])) {
        $productNames = is_array($order['product_names']) ? $order['product_names'] : explode(',', $order['product_names']);
        if (!empty($order['product_skus'])) {
            $skus = is_array($order['product_skus']) ? $order['product_skus'] : explode(',', $order['product_skus']);
        }
    } elseif (!empty($order['product_list'])) {
        $productList = is_array($order['product_list']) ? $order['product_list'] : json_decode($order['product_list'], true);
        if (is_array($productList)) {
            foreach ($productList as $product) {
                $productNames[] = $product['name'] ?? $product['nombre'] ?? '';
                $skus[] = $product['sku'] ?? $product['codigo'] ?? '';
            }
        }
    }
    
    // Mostrar solo los 2 primeros productos
    foreach (array_slice($productNames, 0, 2) as $index => $productName) {
        if (empty($productName)) continue;
        $sku = isset($skus[$index]) ? $skus[$index] : '';
        
        // Guardar en el array de productos ya mostrados
        if (!empty($sku)) {
            $productosYaMostrados[] = $sku;
        }
        
        $productosMostrar[] = "<div class='product-item mb-2'>
                <div class='product-name'>" . $this->escapeHtml(substr($productName, 0, 40)) . (strlen($productName) > 40 ? '...' : '') . "</div>" . 
                (!empty($sku) ? "<div class='product-sku'>" . $this->escapeHtml($sku) . "</div>" : "") . 
                "</div>";
    }
    
    // Indicar si hay más productos
    if (count($productNames) > 2) {
        $productosMostrar[] = "<div class='product-more'>+" . (count($productNames) - 2) . " más</div>";
    }
}

// 2. Si no tenemos suficientes productos, revisar el campo productos
if (empty($productosMostrar) && isset($order['productos'])) {
    $productos = $order['productos'];
    if (is_string($productos)) {
        $productos = json_decode($productos, true);
    }
    
    if (is_array($productos)) {
        $maxToShow = 2; // Mostrar máximo 2 productos
        $mostrados = 0;
        
        foreach ($productos as $producto) {
            if ($mostrados >= $maxToShow) break;
            
            $nombre = $producto['nombre'] ?? $producto['name'] ?? '';
            $sku = $producto['sku'] ?? '';
            $cantidad = $producto['cantidad'] ?? 1;
            
            // Evitar duplicados
            if (!empty($sku) && in_array($sku, $productosYaMostrados)) {
                continue;
            }
            
            // Guardar SKU para evitar duplicados
            if (!empty($sku)) {
                $productosYaMostrados[] = $sku;
            }
            
            if (!empty($nombre)) {
                $productosMostrar[] = "<div class='product-item mb-2'>
                    <div class='product-name'>" . $cantidad . "x " . $this->escapeHtml(substr($nombre, 0, 40)) . (strlen($nombre) > 40 ? '...' : '') . "</div>" . 
                    (!empty($sku) ? "<div class='product-sku'>" . $this->escapeHtml($sku) . "</div>" : "") . 
                    "</div>";
                $mostrados++;
            }
        }
        
        // Indicar si hay más productos
        if (count($productos) > $mostrados) {
            $productosMostrar[] = "<div class='product-more'>+" . (count($productos) - $mostrados) . " más</div>";
        }
    }
}

// 3. Si aún no tenemos suficientes productos, intentar con campo sku/skus
if (empty($productosMostrar)) {
    $skus = $order['sku'] ?? $order['skus'] ?? '';
    
    if (!empty($skus) && is_string($skus)) {
        // Preparar lista de SKUs
        $skuList = [];
        
        // Intentar decodificar como JSON
        $skuArray = json_decode($skus, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($skuArray)) {
            $skuList = $skuArray;
        } else {
            // Si no es JSON, asumir lista separada por comas
            $skuList = preg_split('/[,|;]/', $skus);
            $skuList = array_map('trim', $skuList);
            $skuList = array_filter($skuList);
        }
        
        // Mostrar los 2 primeros SKUs
        foreach (array_slice($skuList, 0, 2) as $index => $sku) {
            if (is_string($sku) && !empty($sku)) {
                // Mostrar producto con solo SKU
                $productosMostrar[] = "<div class='product-item mb-2'>
                    <div class='product-name'>Producto</div>
                    <div class='product-sku'>" . $this->escapeHtml($sku) . "</div>
                </div>";
            }
        }
        
        // Indicar si hay más SKUs
        if (count($skuList) > 2) {
            $productosMostrar[] = "<div class='product-more'>+" . (count($skuList) - 2) . " más</div>";
        }
    }
}

// Mostrar el resultado
if (empty($productosMostrar)) {
    echo "<span class='text-muted'>No disponible</span>";
} else {
    echo implode("\n", $productosMostrar);
}
<?php

class MoneiCardlogosModuleFrontController extends ModuleFrontController
{
    private const CACHE_TTL = 86400; // 24 hours

    private function getCacheDir()
    {
        return _PS_CACHE_DIR_ . 'monei_card_logos/';
    }

    public function initContent()
    {
        // Get available card brands from query parameter
        $brands = Tools::getValue('brands', '');
        $brandsArray = array_filter(explode(',', $brands));

        if (empty($brandsArray)) {
            // Default to common brands
            $brandsArray = ['visa', 'mastercard'];
        }

        // Generate cache key
        sort($brandsArray); // Ensure consistent cache key
        $cacheKey = md5(implode('_', $brandsArray));
        $cacheDir = $this->getCacheDir();
        $cacheFile = $cacheDir . $cacheKey . '.svg';

        // Check if cached version exists and is fresh
        // Temporarily disabled for testing
        /*if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < self::CACHE_TTL)) {
            header('Content-Type: image/svg+xml');
            header('Cache-Control: public, max-age=' . self::CACHE_TTL);
            header('ETag: "' . $cacheKey . '"');
            readfile($cacheFile);
            exit;
        }*/

        // Generate SVG
        $svgContent = $this->generateCombinedSvg($brandsArray);

        // Save to cache - temporarily disabled
        /*if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        file_put_contents($cacheFile, $svgContent);*/

        // Output SVG
        header('Content-Type: image/svg+xml');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $svgContent;
        exit;
    }

    private function generateCombinedSvg(array $brandsArray): string
    {
        // Start buffering output
        ob_start();

        // Use standard dimensions like Magento
        $iconWidth = 40;
        $iconHeight = 24;
        $iconSpacing = 2; // 2px spacing between icons as requested

        // Calculate total width
        $totalWidth = (count($brandsArray) * $iconWidth) + ((count($brandsArray) - 1) * $iconSpacing);
        $containerHeight = 30; // Container height like Magento

        // Create title with list of brands
        $brandNames = array_map('ucfirst', $brandsArray);
        $title = implode(', ', $brandNames);

        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<!-- Generated at ' . date('Y-m-d H:i:s') . ' with spacing: ' . $iconSpacing . 'px -->';
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="' . $totalWidth . '" height="' . $containerHeight . '" viewBox="0 0 ' . $totalWidth . ' ' . $containerHeight . '" role="img" aria-label="' . htmlspecialchars($title) . '">';
        echo '<title>' . htmlspecialchars($title) . '</title>';

        $xOffset = 0;
        $yOffset = 3; // Center icons vertically like Magento (3px from top in 30px container)

        foreach ($brandsArray as $brand) {
            $brand = strtolower(trim($brand));
            $svgPath = _PS_MODULE_DIR_ . 'monei/views/img/payments/' . $brand . '.svg';

            if (file_exists($svgPath)) {
                // Read the SVG content
                $svgContent = file_get_contents($svgPath);

                // Extract viewBox from original SVG if present
                $viewBox = '0 0 100 60'; // default
                if (preg_match('/viewBox\s*=\s*["\']([^"\']+)["\']/', $svgContent, $matches)) {
                    $viewBox = $matches[1];
                }

                // Extract the SVG content without the XML declaration and root svg tag
                $svgContent = preg_replace('/<\?xml[^>]*\?>/', '', $svgContent);
                $svgContent = preg_replace('/<svg[^>]*>/', '', $svgContent);
                $svgContent = preg_replace('/<\/svg>/', '', $svgContent);

                // Wrap in an SVG element positioned at the correct offset
                echo '<svg x="' . $xOffset . '" y="' . $yOffset . '" width="' . $iconWidth . '" height="' . $iconHeight . '" viewBox="' . $viewBox . '" preserveAspectRatio="xMidYMid meet">';
                echo $svgContent;
                echo '</svg>';

                $xOffset += $iconWidth + $iconSpacing;
            }
        }

        echo '</svg>';

        // Get buffered content and clean buffer
        $content = ob_get_clean();

        return $content;
    }

    /**
     * Clean up old cache files
     */
    public function cleanCache(): void
    {
        $cacheDir = $this->getCacheDir();
        if (!is_dir($cacheDir)) {
            return;
        }

        $files = glob($cacheDir . '*.svg');
        $now = time();

        foreach ($files as $file) {
            if ($now - filemtime($file) > self::CACHE_TTL) {
                unlink($file);
            }
        }
    }
}

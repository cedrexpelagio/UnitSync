        </main>
    </div>

    <script src="<?= BASE_URL ?>/assets/js/ui.js"></script>
    <?php 
    if (function_exists('is_logged_in') && is_logged_in()) {
        require_once __DIR__ . '/ai/widget.php';
        echo '<script src="' . BASE_URL . '/assets/js/chat.js" defer></script>' . "\n";
    }
    ?>
</body>
</html>

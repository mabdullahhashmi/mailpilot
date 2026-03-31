        </main>
    </div>
    
    <div class="toast-container" id="toastContainer"></div>
    
    <script src="<?= $basePath ?>/assets/js/app.js"></script>
    <?php if (isset($loadTinyMCE) && $loadTinyMCE): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.4/tinymce.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            initTinyMCE('#emailBody', '<?= $basePath ?>/api/upload-image.php');
        });
    </script>
    <?php endif; ?>
    <?php if (isset($pageScript)): ?>
    <script><?= $pageScript ?></script>
    <?php endif; ?>
</body>
</html>

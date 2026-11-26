<!-- FIX: Script order corrected — jQuery must load BEFORE DataTables and Bootstrap -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/main.js?v=<?= filemtime(__DIR__.'/assets/js/main.js') ?>"></script>

</body>
</html>
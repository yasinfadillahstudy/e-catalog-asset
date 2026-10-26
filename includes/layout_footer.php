    </div> <!-- /.content -->
</div> <!-- /.main -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // ============================================
    // SIDEBAR TOGGLE MOBILE
    // ============================================
    function toggleSidebar() {
        document.body.classList.toggle('sidebar-open');
    }
    function closeSidebar() {
        document.body.classList.remove('sidebar-open');
    }

    // Auto-close sidebar saat resize ke desktop
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            document.body.classList.remove('sidebar-open');
        }
    });

    // Auto-hide flash message setelah 5 detik
    document.addEventListener('DOMContentLoaded', function() {
        const flash = document.querySelector('.flash');
        if (flash) {
            setTimeout(() => {
                flash.style.transition = 'opacity 0.3s, transform 0.3s';
                flash.style.opacity = '0';
                flash.style.transform = 'translateY(-10px)';
                setTimeout(() => flash.remove(), 300);
            }, 5000);
        }

        // Tandai tabel yang perlu scroll di mobile
        document.querySelectorAll('table.table, table.data-table').forEach(tbl => {
            const parent = tbl.parentElement;
            if (parent && !parent.classList.contains('table-responsive')) {
                if (parent.tagName === 'DIV' && parent.style.overflowX) return;
                // Biarkan saja kalau sudah punya .table-responsive
            }
        });
    });
</script>

</body>
</html>
<?php
/**
 * Closes the markup includes/internal_header.php opens (.main-content,
 * .app-body) when a user is authenticated; the login page (no $user,
 * no shell opened) just needs the bare </body></html>. Every page under
 * /admin, /staff, /department-head uses THIS footer instead of the plain
 * includes/footer.php used by the public/client side, which is untouched.
 */
if ($user ?? null): ?>
  </main>
</div>
<script src="/assets/app.js" defer></script>
<?php endif; ?>
</body>
</html>

<?php
$rol = (int)($_SESSION['user_rol'] ?? 0);
if (in_array($rol, [2,3], true)): ?>
  <div class="eqf-modal-backdrop" id="announceModal" style="display:none;">
    <div class="eqf-modal eqf-announce-modal" data-ann-modal>
      <div class="eqf-modal-header">
        <div>
          <strong>Nuevo aviso</strong>
          <div class="panel-muted">Se mostrará en “Resumen” del usuario.</div>
        </div>
        <button class="eqf-modal-close" type="button" data-close-announcement>✕</button>
      </div>

      <div class="eqf-modal-body eqf-announce-body">
        <div class="eqf-field">
          <label>Título</label>
          <input type="text" id="ann_title" maxlength="120">
        </div>

        <div class="eqf-field" style="margin-top:10px;">
          <label>Descripción</label>
          <textarea id="ann_body" rows="4" maxlength="600"></textarea>
        </div>

        <div class="eqf-grid-2" style="margin-top:10px;">
          <div class="eqf-field">
            <label>Categoría</label>
            <select id="ann_level">
              <option value="INFO">INFORMATIVO</option>
              <option value="WARN">ADVERTENCIA</option>
              <option value="CRITICAL">CRITICO</option>
            </select>
          </div>

          <div class="eqf-field">
            <label>Área</label>
            <select id="ann_area">
              <option value="ALL">Todos</option>
              <option value="Sucursal">Sucursal</option>
              <option value="Corporativo">Corporativo</option>
            </select>
          </div>
        </div>

        <div class="eqf-grid-2" style="margin-top:10px;">
          <div class="eqf-field">
            <label>Inicio (opcional)</label>
            <input type="datetime-local" id="ann_starts">
          </div>
          <div class="eqf-field">
            <label>Fin (opcional)</label>
            <input type="datetime-local" id="ann_ends">
          </div>
        </div>
      </div>

      <div class="eqf-modal-footer">
        <button class="eqf-btn eqf-btn-secondary" type="button" data-cancel-announcement>Cancelar</button>
        <button class="eqf-btn eqf-btn-primary" type="button" id="btnSendAnnouncement">Enviar</button>
      </div>
    </div>
  </div>
<?php endif; ?>

<footer>
    <div class="box__copyright">
        <p>
            Todos los derechos reservados ©2026
            <b>Equilibrio Farmacéutico</b>
            <span class="logoNR"></span>
        </p>
    </div>

    <?php
    $eqf_footer_loaded = true;

    if ($eqf_footer_loaded) {
        echo base64_decode("PHN0eWxlPi5sb2dvTlI6OmFmdGVye2NvbnRlbnQ6IkJBU0MiO29wYWNpdHk6MC4xODtmb250LXNpemU6MTFweDttYXJnaW4tbGVmdDo4cHg7dXNlci1zZWxlY3Q6bm9uZTtwb2ludGVyLWV2ZW50czpub25lO3Bvc2l0aW9uOnJlbGF0aXZlO3RvcDotMXB4O308L3N0eWxlPg==");
    }
    ?>

    <script>
        window.HELPDESK_USER_ID = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
        window.HELPDESK_USE_NOTI_PUSH = true;
    </script>

    <script src="/HelpDesk_EQF/assets/js/noti_push.js?v=<?php echo time(); ?>"></script>
    <script src="/HelpDesk_EQF/assets/js/sidebar.js" defer></script>
   <script src="/HelpDesk_EQF/assets/js/script.js" defer></script> 
      <script src="/HelpDesk_EQF/assets/js/announcements_modal.js" defer></script>

</footer>

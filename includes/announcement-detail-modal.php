<?php
/**
 * Announcement detail modal markup.
 * This file is included by `announcement-modal-bundle.php` when present.
 */
?>
<!-- Announcement Detail Modal -->
<div class="modal fade" id="announcementDetailModal" tabindex="-1" role="dialog" aria-labelledby="announcementDetailModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="announcementDetailModalLabel">Announcement</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <p id="announcementDetailSender" class="mb-1 text-muted"></p>
        <p id="announcementDetailTimestamp" class="mb-3 text-muted small"></p>

        <div id="announcementDetailLoading" class="text-center my-4">
          <div class="spinner-border text-secondary" role="status"><span class="sr-only">Loading...</span></div>
        </div>

        <div id="announcementDetailError" class="alert alert-danger d-none" role="alert"></div>

        <div id="announcementDetailBody" class="mb-3" style="white-space:pre-wrap;"></div>

        <div id="announcementDetailAttachments" class="d-none">
          <h6>Attachments</h6>
          <ul id="announcementDetailAttachmentsList" class="list-unstyled"></ul>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

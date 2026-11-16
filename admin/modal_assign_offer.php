<?php
/**
 * Shared Assign Offer Modal & Instant Tracking Link Generator
 * Allows admins & managers to assign campaigns to publishers, configure custom payouts,
 * and generate live tracking links with 1-click copy and instant social sharing (WhatsApp, Telegram, Email, Skype).
 */

if (!defined('APP_INIT')) {
    die('Direct access not allowed');
}

// Fetch active publishers if not already defined
if (!isset($modalPublishersList)) {
    try {
        $modalPublishersList = $pdo->query("
            SELECT user_id, name, email, company, status 
            FROM users 
            WHERE role_id = 3 
            ORDER BY status = 'active' DESC, name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $modalPublishersList = [];
    }
}

// Fetch active offers if not already defined
if (!isset($modalOffersList)) {
    try {
        $modalOffersList = $pdo->query("
            SELECT offer_id, offer_name, payout, revenue, currency, status 
            FROM offers 
            WHERE status != 'archived' 
            ORDER BY offer_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $modalOffersList = [];
    }
}
?>

<!-- Select2 Assets for Modal Searchable Dropdowns -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css">

<!-- Assign Offer & Link Generator Modal -->
<div class="modal fade" id="assignOfferModal" tabindex="-1" role="dialog" aria-labelledby="assignOfferModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content shadow-lg border-0" style="border-radius: 14px; overflow: hidden;">
            
            <!-- Modal Header -->
            <div class="modal-header bg-primary text-white py-3 px-4">
                <h5 class="modal-title font-weight-bold" id="assignOfferModalLabel">
                    <i class="fas fa-link mr-2"></i> Assign Campaign & Generate Live Tracking Link
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" style="opacity: 0.9;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body p-4" style="background: #fbfcfe;">
                
                <!-- Error Alert Box -->
                <div id="assignModalError" class="alert alert-danger d-none" role="alert">
                    <i class="fas fa-exclamation-triangle mr-2"></i><span id="assignModalErrorText"></span>
                </div>

                <!-- Assignment Form -->
                <form id="assignOfferForm" onsubmit="return false;">
                    
                    <div class="row">
                        <!-- Select Campaign / Offer -->
                        <div class="col-md-7 mb-3">
                            <label class="font-weight-bold text-dark">
                                Campaign / Offer <span class="text-danger">*</span>
                            </label>
                            <select id="modalOfferSelect" class="form-control select2" style="width: 100%;" required>
                                <option value="">-- Select Campaign --</option>
                                <?php foreach ($modalOffersList as $mof): 
                                    $curr = $mof['currency'] ?: 'USD';
                                    $sym = ($curr === 'INR') ? '₹' : '$';
                                ?>
                                <option value="<?php echo $mof['offer_id']; ?>" 
                                        data-name="<?php echo htmlspecialchars($mof['offer_name'], ENT_QUOTES); ?>"
                                        data-payout="<?php echo htmlspecialchars($mof['payout']); ?>"
                                        data-currency="<?php echo htmlspecialchars($curr); ?>"
                                        data-symbol="<?php echo htmlspecialchars($sym); ?>">
                                    #<?php echo $mof['offer_id']; ?> - <?php echo htmlspecialchars($mof['offer_name']); ?> (Default: <?php echo $sym . number_format($mof['payout'], 2) . ' ' . $curr; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">The campaign offer to assign to the publisher.</small>
                        </div>

                        <!-- Select Publisher / Affiliate -->
                        <div class="col-md-5 mb-3">
                            <label class="font-weight-bold text-dark">
                                Publisher / Affiliate <span class="text-danger">*</span>
                            </label>
                            <select id="modalPublisherSelect" class="form-control select2" style="width: 100%;" required>
                                <option value="">-- Search Publisher --</option>
                                <?php foreach ($modalPublishersList as $mpub): 
                                    $isBlocked = in_array(strtolower($mpub['status']), ['blocked', 'banned', 'suspended'], true);
                                ?>
                                <option value="<?php echo $mpub['user_id']; ?>" 
                                        data-name="<?php echo htmlspecialchars($mpub['name'], ENT_QUOTES); ?>"
                                        data-email="<?php echo htmlspecialchars($mpub['email'], ENT_QUOTES); ?>"
                                        data-company="<?php echo htmlspecialchars($mpub['company'] ?: '', ENT_QUOTES); ?>"
                                        data-status="<?php echo htmlspecialchars($mpub['status']); ?>"
                                        <?php echo $isBlocked ? 'disabled class="text-muted"' : ''; ?>>
                                    #<?php echo $mpub['user_id']; ?> - <?php echo htmlspecialchars($mpub['name']); ?> <?php echo $mpub['company'] ? '('.htmlspecialchars($mpub['company']).')' : ''; ?> <?php echo $isBlocked ? '[BLOCKED]' : ''; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">Active publisher who will run this campaign.</small>
                        </div>
                    </div>

                    <div class="row">
                        <!-- Custom Publisher Payout -->
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark">
                                Publisher Payout (<span id="modalCurrencyBadge">USD</span>)
                            </label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text font-weight-bold bg-white" id="modalCurrencySymbol">$</span>
                                </div>
                                <input type="number" step="0.01" min="0" id="modalCustomPayout" class="form-control font-weight-bold" placeholder="0.00">
                            </div>
                            <small class="form-text text-muted">Custom payout rate. Defaults to campaign's standard payout.</small>
                        </div>

                        <!-- Sub ID 1 (Optional) -->
                        <div class="col-md-3 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Sub ID 1 <span class="text-muted">(Optional)</span></label>
                            <input type="text" id="modalSub1" class="form-control" placeholder="e.g. facebook">
                        </div>

                        <!-- Sub ID 2 (Optional) -->
                        <div class="col-md-3 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Sub ID 2 <span class="text-muted">(Optional)</span></label>
                            <input type="text" id="modalSub2" class="form-control" placeholder="e.g. adset_01">
                        </div>
                    </div>

                    <!-- Internal Note (Optional) -->
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small text-uppercase">Admin Assignment Note <span class="text-muted">(Optional)</span></label>
                        <input type="text" id="modalNotes" class="form-control" placeholder="e.g. Approved via Direct Agreement / Special Payout Tier">
                    </div>

                    <!-- Generate Action Button -->
                    <div class="mt-4">
                        <button type="button" id="btnGenerateTrackingLink" class="btn btn-success btn-block py-2 font-weight-bold shadow-sm" style="font-size: 16px; border-radius: 8px;">
                            <i class="fas fa-bolt mr-2"></i> Assign Campaign & Generate Live Tracking Link
                        </button>
                    </div>

                </form>

                <!-- ==============================================
                     GENERATED TRACKING LINK RESULT SECTION
                =============================================== -->
                <div id="assignResultContainer" class="mt-4 d-none">
                    <hr style="border-top: 2px dashed #cbd5e1;">

                    <!-- Success Alert Banner -->
                    <div class="alert alert-success d-flex align-items-center mb-3 shadow-sm" style="border-radius: 8px; border-left: 5px solid #10b981;">
                        <i class="fas fa-check-circle fa-2x mr-3 text-success"></i>
                        <div>
                            <h6 class="mb-0 font-weight-bold text-success" id="resultSuccessTitle">Campaign Successfully Assigned!</h6>
                            <small class="text-muted" id="resultSuccessSubtitle">Unique tracking link generated and active for immediate traffic.</small>
                        </div>
                    </div>

                    <!-- Full Tracking URL Display -->
                    <div class="card card-body bg-white border mb-3 p-3 shadow-sm" style="border-radius: 10px;">
                        <label class="font-weight-bold text-dark mb-1">
                            <i class="fas fa-link text-primary mr-1"></i> Unique Tracking Link (Ready to Share):
                        </label>
                        <div class="input-group">
                            <input type="text" id="generatedTrackingUrl" class="form-control font-weight-bold text-primary" style="background: #f8fafc; font-size: 13.5px;" readonly>
                            <div class="input-group-append">
                                <button class="btn btn-primary font-weight-bold px-3" type="button" id="btnCopyGeneratedUrl">
                                    <i class="fas fa-copy mr-1"></i> Copy Link
                                </button>
                            </div>
                        </div>
                        <div id="copySuccessFeedback" class="text-success small font-weight-bold mt-2 d-none">
                            <i class="fas fa-check mr-1"></i> Copied to clipboard successfully!
                        </div>
                    </div>

                    <!-- Share & Actions Toolbar (WhatsApp, Telegram, Email, Skype, Test Link) -->
                    <div class="mb-3">
                        <label class="font-weight-bold small text-uppercase text-muted d-block mb-2">
                            <i class="fas fa-share-alt mr-1"></i> Share & Distribute to Publisher:
                        </label>
                        <div class="d-flex flex-wrap" style="gap: 8px;">
                            <a href="#" id="shareWhatsAppBtn" target="_blank" class="btn btn-success font-weight-bold shadow-sm">
                                <i class="fab fa-whatsapp mr-1"></i> WhatsApp
                            </a>
                            <a href="#" id="shareTelegramBtn" target="_blank" class="btn btn-info font-weight-bold shadow-sm">
                                <i class="fab fa-telegram-plane mr-1"></i> Telegram
                            </a>
                            <a href="#" id="shareEmailBtn" class="btn btn-secondary font-weight-bold shadow-sm">
                                <i class="fas fa-envelope mr-1"></i> Email
                            </a>
                            <a href="#" id="shareSkypeBtn" target="_blank" class="btn btn-primary font-weight-bold shadow-sm">
                                <i class="fab fa-skype mr-1"></i> Skype
                            </a>
                            <a href="#" id="testTrackingLinkBtn" target="_blank" class="btn btn-outline-dark font-weight-bold shadow-sm">
                                <i class="fas fa-external-link-alt mr-1"></i> Test Link in Browser
                            </a>
                        </div>
                    </div>

                    <!-- Assignment Summary Details Table -->
                    <div class="table-responsive bg-white rounded border">
                        <table class="table table-sm table-bordered mb-0 small">
                            <tbody>
                                <tr>
                                    <th class="bg-light" style="width: 28%;">Campaign</th>
                                    <td id="resultOfferInfo" class="font-weight-bold text-dark">-</td>
                                </tr>
                                <tr>
                                    <th class="bg-light">Assigned Publisher</th>
                                    <td id="resultPublisherInfo" class="font-weight-bold text-dark">-</td>
                                </tr>
                                <tr>
                                    <th class="bg-light">Approved Payout</th>
                                    <td id="resultPayoutInfo" class="font-weight-bold text-success">-</td>
                                </tr>
                                <tr>
                                    <th class="bg-light">Supported Macros</th>
                                    <td class="text-muted font-monospace"><code>{click_id}</code>, <code>{sub1}</code>, <code>{sub2}</code>, <code>{sub3}</code>, <code>{sub4}</code>, <code>{sub5}</code></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                </div>

            </div>

            <!-- Modal Footer -->
            <div class="modal-footer bg-light py-2 px-4">
                <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Close</button>
            </div>

        </div>
    </div>
</div>

<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- Assign Offer JavaScript Controller -->
<script>
(function() {
    // Initialize modal controls when DOM is ready
    $(document).ready(function() {
        // Ensure Select2 works smoothly inside Bootstrap modal
        if ($.fn.select2) {
            $('#modalOfferSelect').select2({
                theme: 'bootstrap4',
                dropdownParent: $('#assignOfferModal'),
                placeholder: '-- Select Campaign --'
            });

            $('#modalPublisherSelect').select2({
                theme: 'bootstrap4',
                dropdownParent: $('#assignOfferModal'),
                placeholder: '-- Search Publisher --'
            });
        }

        // Handle Offer Selection change
        $('#modalOfferSelect').on('change', function() {
            var selected = $(this).find(':selected');
            var payout = selected.data('payout') || '';
            var currency = selected.data('currency') || 'USD';
            var symbol = selected.data('symbol') || (currency === 'INR' ? '₹' : '$');

            $('#modalCustomPayout').val(payout);
            $('#modalCurrencyBadge').text(currency);
            $('#modalCurrencySymbol').text(symbol);
        });

        // Open Modal Handler for buttons across tables
        $(document).on('click', '.open-assign-modal', function(e) {
            e.preventDefault();
            var btn = $(this);
            var offerId = btn.data('offer-id');
            var offerName = btn.data('offer-name');
            var payout = btn.data('payout');
            var currency = btn.data('currency') || 'USD';
            var symbol = (currency === 'INR') ? '₹' : '$';

            // Reset modal state
            $('#assignModalError').addClass('d-none');
            $('#assignResultContainer').addClass('d-none');
            $('#copySuccessFeedback').addClass('d-none');
            $('#modalSub1').val('');
            $('#modalSub2').val('');
            $('#modalNotes').val('');

            if (offerId) {
                $('#modalOfferSelect').val(offerId).trigger('change');
                if (payout !== undefined && payout !== null) {
                    $('#modalCustomPayout').val(payout);
                }
                $('#modalCurrencyBadge').text(currency);
                $('#modalCurrencySymbol').text(symbol);
            } else {
                $('#modalOfferSelect').val('').trigger('change');
                $('#modalCustomPayout').val('');
            }

            // Show Modal
            $('#assignOfferModal').modal('show');
        });

        // Generate Tracking Link Button Click
        $('#btnGenerateTrackingLink').on('click', function() {
            var btn = $(this);
            var offerId = $('#modalOfferSelect').val();
            var publisherId = $('#modalPublisherSelect').val();
            var customPayout = $('#modalCustomPayout').val();
            var sub1 = $('#modalSub1').val().trim();
            var sub2 = $('#modalSub2').val().trim();
            var notes = $('#modalNotes').val().trim();

            $('#assignModalError').addClass('d-none');
            $('#copySuccessFeedback').addClass('d-none');

            if (!offerId) {
                $('#assignModalErrorText').text('Please select a Campaign / Offer.');
                $('#assignModalError').removeClass('d-none');
                return;
            }

            if (!publisherId) {
                $('#assignModalErrorText').text('Please select a Publisher / Affiliate.');
                $('#assignModalError').removeClass('d-none');
                return;
            }

            // Button loading state
            var originalBtnHtml = btn.html();
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i> Generating Unique Link...');

            $.ajax({
                url: 'ajax_assign_offer.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'assign',
                    offer_id: offerId,
                    publisher_id: publisherId,
                    custom_payout: customPayout,
                    sub1: sub1,
                    sub2: sub2,
                    notes: notes
                },
                success: function(resp) {
                    btn.prop('disabled', false).html(originalBtnHtml);

                    if (resp && resp.success) {
                        var trackingUrl = resp.tracking_url;
                        var offerName = resp.offer_name || ('Offer #' + resp.offer_id);
                        var pubName = resp.publisher_name || ('Publisher #' + resp.publisher_id);
                        var pubEmail = resp.publisher_email ? ' (' + resp.publisher_email + ')' : '';
                        var formattedPayout = resp.formatted_payout || (resp.currency + ' ' + resp.payout);

                        // Populate Result Container
                        $('#generatedTrackingUrl').val(trackingUrl);
                        $('#resultOfferInfo').text('#' + resp.offer_id + ' - ' + offerName);
                        $('#resultPublisherInfo').text('#' + resp.publisher_id + ' - ' + pubName + pubEmail);
                        $('#resultPayoutInfo').text(formattedPayout);

                        // WhatsApp Share Link
                        var waMsg = "Hi " + pubName + ",\nHere is your exclusive tracking link for " + offerName + ":\n" + trackingUrl + "\nPayout: " + formattedPayout;
                        $('#shareWhatsAppBtn').attr('href', 'https://api.whatsapp.com/send?text=' + encodeURIComponent(waMsg));

                        // Telegram Share Link
                        var tgText = "Exclusive Campaign: " + offerName + " (" + formattedPayout + ")";
                        $('#shareTelegramBtn').attr('href', 'https://t.me/share/url?url=' + encodeURIComponent(trackingUrl) + '&text=' + encodeURIComponent(tgText));

                        // Email Share Link
                        var mailSubject = "Exclusive Tracking Link: " + offerName;
                        var mailBody = "Hi " + pubName + ",\n\nHere is your tracking link for " + offerName + ":\n" + trackingUrl + "\n\nApproved Payout: " + formattedPayout + "\n\nBest regards,\nOffer on Media Team";
                        $('#shareEmailBtn').attr('href', 'mailto:' + encodeURIComponent(resp.publisher_email || '') + '?subject=' + encodeURIComponent(mailSubject) + '&body=' + encodeURIComponent(mailBody));

                        // Skype Share Link
                        $('#shareSkypeBtn').attr('href', 'https://web.skype.com/share?url=' + encodeURIComponent(trackingUrl) + '&text=' + encodeURIComponent(offerName));

                        // Test Link
                        $('#testTrackingLinkBtn').attr('href', trackingUrl);

                        // Reveal Container & Smooth Scroll
                        $('#assignResultContainer').removeClass('d-none');
                        $('#assignOfferModal').animate({
                            scrollTop: $('#assignResultContainer').offset().top
                        }, 400);

                    } else {
                        var errMsg = (resp && resp.error) ? resp.error : 'Failed to assign campaign. Please try again.';
                        $('#assignModalErrorText').text(errMsg);
                        $('#assignModalError').removeClass('d-none');
                    }
                },
                error: function(xhr) {
                    btn.prop('disabled', false).html(originalBtnHtml);
                    var errMsg = 'Server error occurred during assignment.';
                    try {
                        var parsed = JSON.parse(xhr.responseText);
                        if (parsed && parsed.error) errMsg = parsed.error;
                    } catch (e) {
                        if (xhr.responseText && xhr.responseText.length < 300) {
                            errMsg = xhr.responseText;
                        }
                    }
                    $('#assignModalErrorText').text(errMsg);
                    $('#assignModalError').removeClass('d-none');
                }
            });
        });

        // Copy Tracking Link to Clipboard
        $('#btnCopyGeneratedUrl').on('click', function() {
            var input = document.getElementById('generatedTrackingUrl');
            input.select();
            input.setSelectionRange(0, 99999);

            try {
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(input.value).then(showCopied, fallbackCopy);
                } else {
                    fallbackCopy();
                }
            } catch (err) {
                fallbackCopy();
            }

            function showCopied() {
                $('#copySuccessFeedback').removeClass('d-none');
                setTimeout(function() {
                    $('#copySuccessFeedback').addClass('d-none');
                }, 3000);
            }

            function fallbackCopy() {
                try {
                    document.execCommand('copy');
                    showCopied();
                } catch (e) {
                    alert('Please copy manually: ' + input.value);
                }
            }
        });
    });
})();
</script>

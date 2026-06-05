/**
 * Portail Entreprises B2B — Scripts frontend
 * Vanilla JS + wp.ajax (jQuery disponible via WP)
 */

/* global peB2B, jQuery */

(function ($) {
    'use strict';

    var PE = {

        /**
         * Initialisation
         */
        init: function () {
            this.bindApprovalActions();
            this.bindRemoveUserActions();
            this.bindCostCenterActions();
            this.bindAdminActions();
            this.bindModalClose();
            this.animateProgressBars();
        },

        /**
         * Actions d'approbation (approuver / rejeter)
         */
        bindApprovalActions: function () {
            var self = this;

            // Bouton Approuver
            $(document).on('click', '.pe-approve-btn', function () {
                var $btn       = $(this);
                var requestId  = $btn.data('request-id');
                var nonce      = $btn.data('nonce');

                if (!window.confirm(peB2B.i18n.confirmApprove)) {
                    return;
                }

                self.setButtonLoading($btn);

                $.ajax({
                    url:    peB2B.ajaxUrl,
                    method: 'POST',
                    data: {
                        action:     'pe_approve_request',
                        nonce:      nonce,
                        request_id: requestId
                    },
                    success: function (response) {
                        if (response.success) {
                            self.showFeedback('success', response.data.message);
                            self.removeApprovalRow($btn);
                        } else {
                            self.showFeedback('error', response.data.message || peB2B.i18n.error);
                            self.resetButton($btn);
                        }
                    },
                    error: function () {
                        self.showFeedback('error', peB2B.i18n.error);
                        self.resetButton($btn);
                    }
                });
            });

            // Bouton Rejeter — ouvre modal
            $(document).on('click', '.pe-reject-btn', function () {
                var $btn      = $(this);
                var requestId = $btn.data('request-id');
                var nonce     = $btn.data('nonce');
                var $modal    = $('#pe-reject-modal');

                if (!$modal.length) {
                    // Pas de modal — demande directe
                    var reason = window.prompt(peB2B.i18n.confirmReject, '');
                    if (reason === null) return;

                    self.setButtonLoading($btn);
                    self.doReject(requestId, nonce, reason, $btn);
                    return;
                }

                $modal.data('request-id', requestId).data('nonce', nonce).data('source-btn', $btn);
                $modal.show();
                $('#pe-reject-reason').focus();
            });

            // Confirmation du rejet depuis le modal
            $(document).on('click', '#pe-confirm-reject', function () {
                var $modal    = $('#pe-reject-modal');
                var requestId = $modal.data('request-id');
                var nonce     = $modal.data('nonce');
                var $btn      = $modal.data('source-btn');
                var reason    = $('#pe-reject-reason').val().trim();

                $modal.hide();
                if ($btn) {
                    self.setButtonLoading($btn);
                }
                self.doReject(requestId, nonce, reason, $btn);
            });
        },

        /**
         * Exécute la requête AJAX de rejet.
         */
        doReject: function (requestId, nonce, reason, $btn) {
            var self = this;

            $.ajax({
                url:    peB2B.ajaxUrl,
                method: 'POST',
                data: {
                    action:     'pe_reject_request',
                    nonce:      nonce,
                    request_id: requestId,
                    reason:     reason
                },
                success: function (response) {
                    if (response.success) {
                        self.showFeedback('success', response.data.message);
                        if ($btn) {
                            self.removeApprovalRow($btn);
                        }
                    } else {
                        self.showFeedback('error', response.data.message || peB2B.i18n.error);
                        if ($btn) {
                            self.resetButton($btn);
                        }
                    }
                },
                error: function () {
                    self.showFeedback('error', peB2B.i18n.error);
                    if ($btn) {
                        self.resetButton($btn);
                    }
                }
            });
        },

        /**
         * Supprime la ligne d'une demande d'approbation de la table.
         */
        removeApprovalRow: function ($btn) {
            var $row = $btn.closest('tr.pe-approval-row');
            if ($row.length) {
                $row.fadeOut(300, function () {
                    $row.remove();
                });
            }
        },

        /**
         * Retirer un utilisateur d'une entreprise (frontend + admin)
         */
        bindRemoveUserActions: function () {
            var self = this;

            $(document).on('click', '.pe-remove-user', function () {
                var $btn      = $(this);
                var userId    = $btn.data('user-id');
                var companyId = $btn.data('company-id');
                var nonce     = $btn.data('nonce');

                if (!window.confirm(peB2B.i18n.confirmDelete)) {
                    return;
                }

                self.setButtonLoading($btn);

                $.ajax({
                    url:    peB2B.ajaxUrl,
                    method: 'POST',
                    data: {
                        action:     'pe_admin_remove_user_from_company',
                        nonce:      nonce,
                        user_id:    userId,
                        company_id: companyId
                    },
                    success: function (response) {
                        if (response.success) {
                            var $row = $btn.closest('tr');
                            $row.fadeOut(300, function () { $row.remove(); });
                            self.showFeedback('success', response.data.message);
                        } else {
                            self.showFeedback('error', response.data.message || peB2B.i18n.error);
                            self.resetButton($btn);
                        }
                    },
                    error: function () {
                        self.showFeedback('error', peB2B.i18n.error);
                        self.resetButton($btn);
                    }
                });
            });
        },

        /**
         * Population dynamique du centre de coût au checkout.
         */
        bindCostCenterActions: function () {
            // Si des centres de coût doivent être chargés dynamiquement via AJAX.
            // Dans notre implémentation, ils sont déjà dans le champ select WooCommerce.
            // Cette fonction gère l'affichage conditionnel si nécessaire.
            var $costCenter = $('#b2b_cost_center');

            if ($costCenter.length) {
                $costCenter.closest('.form-row').addClass('pe-checkout-cost-center');
            }
        },

        /**
         * Actions admin spécifiques.
         */
        bindAdminActions: function () {
            var self = this;

            // Ajout d'un centre de coût via admin
            $(document).on('click', '#pe-add-cost-center', function () {
                var $btn      = $(this);
                var companyId = $btn.data('company');
                var nonce     = $btn.data('nonce');
                var name      = $('#new_cc_name').val().trim();
                var code      = $('#new_cc_code').val().trim();

                if (!name) {
                    alert('Veuillez saisir un nom pour le centre de coût.');
                    return;
                }

                self.setButtonLoading($btn);

                $.ajax({
                    url:    peB2B.ajaxUrl,
                    method: 'POST',
                    data: {
                        action:     'pe_admin_create_cost_center',
                        nonce:      nonce,
                        company_id: companyId,
                        name:       name,
                        code:       code
                    },
                    success: function (response) {
                        self.resetButton($btn);
                        if (response.success) {
                            // Ajouter la ligne dans la table (si elle existe)
                            var $table = $('.pe-company-form').find('table');
                            if ($table.length) {
                                window.location.reload();
                            } else {
                                self.showFeedback('success', response.data.message);
                                $('#new_cc_name').val('');
                                $('#new_cc_code').val('');
                            }
                        } else {
                            self.showFeedback('error', response.data.message || peB2B.i18n.error);
                        }
                    },
                    error: function () {
                        self.resetButton($btn);
                        self.showFeedback('error', peB2B.i18n.error);
                    }
                });
            });

            // Suppression d'une société — double confirmation
            $(document).on('click', '.pe-delete-company', function () {
                var $btn        = $(this);
                var companyId   = $btn.data('company-id');
                var companyName = $btn.data('company-name');
                var nonce       = $btn.data('nonce');

                // 1ère validation
                var msg1 = (peB2B.i18n.confirmDeleteCompany || 'Êtes-vous sûr de vouloir supprimer la société « %s » ?')
                    .replace('%s', companyName);
                if (!window.confirm(msg1)) {
                    return;
                }

                // 2ème validation (irréversible)
                var msg2 = peB2B.i18n.confirmDeleteCompanyFinal ||
                    'ATTENTION : cette action est irréversible. Toutes les données associées (utilisateurs, budgets, agences, approbations) seront supprimées. Confirmer définitivement ?';
                if (!window.confirm(msg2)) {
                    return;
                }

                self.setButtonLoading($btn);

                $.ajax({
                    url:    peB2B.ajaxUrl,
                    method: 'POST',
                    data: {
                        action:     'pe_admin_delete_company',
                        nonce:      nonce,
                        company_id: companyId
                    },
                    success: function (response) {
                        if (response.success) {
                            if (response.data && response.data.redirect) {
                                window.location.href = response.data.redirect;
                            } else {
                                var $row = $btn.closest('tr');
                                $row.fadeOut(300, function () { $row.remove(); });
                            }
                        } else {
                            self.showFeedback('error', (response.data && response.data.message) || peB2B.i18n.error);
                            self.resetButton($btn);
                        }
                    },
                    error: function () {
                        self.showFeedback('error', peB2B.i18n.error);
                        self.resetButton($btn);
                    }
                });
            });

            // Suppression d'une règle d'approbation
            $(document).on('click', '.pe-delete-approval-rule', function () {
                var $btn      = $(this);
                var ruleId    = $btn.data('rule-id');
                var companyId = $btn.data('company-id');
                var nonce     = $btn.data('nonce');

                if (!window.confirm(peB2B.i18n.confirmDelete)) {
                    return;
                }

                self.setButtonLoading($btn);

                $.ajax({
                    url:    peB2B.ajaxUrl,
                    method: 'POST',
                    data: {
                        action:     'pe_admin_delete_approval_rule',
                        nonce:      nonce,
                        rule_id:    ruleId,
                        company_id: companyId
                    },
                    success: function (response) {
                        if (response.success) {
                            var $row = $btn.closest('tr');
                            $row.fadeOut(300, function () { $row.remove(); });
                        } else {
                            self.showFeedback('error', response.data.message || peB2B.i18n.error);
                            self.resetButton($btn);
                        }
                    },
                    error: function () {
                        self.showFeedback('error', peB2B.i18n.error);
                        self.resetButton($btn);
                    }
                });
            });
        },

        /**
         * Fermeture du modal.
         */
        bindModalClose: function () {
            $(document).on('click', '.pe-modal-close, .pe-modal-overlay', function () {
                $(this).closest('.pe-modal').hide();
            });

            $(document).on('keydown', function (e) {
                if (27 === e.keyCode) { // Escape
                    $('.pe-modal').hide();
                }
            });
        },

        /**
         * Animation des barres de progression au chargement.
         */
        animateProgressBars: function () {
            $('.pe-progress-bar').each(function () {
                var $bar    = $(this);
                var percent = parseFloat($bar.data('percent')) || parseFloat($bar.css('width'));
                $bar.css('width', 0).animate({ width: percent + '%' }, 600);
            });
        },

        /**
         * Affiche un message de retour AJAX.
         */
        showFeedback: function (type, message) {
            var $feedback = $('#pe-approvals-feedback');

            if (!$feedback.length) {
                $feedback = $('<div id="pe-approvals-feedback" class="pe-ajax-message"></div>');
                $('.pe-approvals-page, .pe-users-page, .pe-company-page').first().prepend($feedback);
            }

            $feedback
                .removeClass('success error')
                .addClass(type)
                .text(message)
                .show()
                .delay(5000)
                .fadeOut();
        },

        /**
         * Met un bouton en état "chargement".
         */
        setButtonLoading: function ($btn) {
            $btn.prop('disabled', true)
                .data('original-text', $btn.text())
                .text(peB2B.i18n.processing || 'Traitement…');
        },

        /**
         * Remet un bouton en état normal.
         */
        resetButton: function ($btn) {
            if ($btn) {
                $btn.prop('disabled', false).text($btn.data('original-text') || $btn.text());
            }
        }
    };

    // Lancement après chargement du DOM
    $(document).ready(function () {
        if (typeof peB2B !== 'undefined') {
            PE.init();
        }
    });

})(jQuery);

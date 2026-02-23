jQuery(document).ready(function($) {
    const ajaxurl = ispagDashboard.ajax_url;
    const nonce = ispagDashboard.nonce;
    const currentYear = ispagDashboard.current_year;
    
    const PROJECT_URL_BASE = 'https://app.ispag-asp.ch/details-du-projet/?deal_id=';

    // --- UTILS ---
    function formatCurrency(amount) {
        return new Intl.NumberFormat('fr-CH', { 
            style: 'currency', 
            currency: 'CHF',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(amount);
    }
    function formatCurrencyWithDecimals(amount) {
        return new Intl.NumberFormat('fr-CH', { 
            style: 'currency', 
            currency: 'CHF',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(amount);
    }

    // --- PAGE 1 : TABLEAU DE BORD PRINCIPAL (LIVRAISON/FACTURATION) ---

    function initProjectDashboard() {
        const $forecastBody = $('#forecast-body');
        const $invoiceBody = $('#invoice-body');
        const $modal = $('#ispag-pd-modal');
        const $modalMonthLabel = $('#modal-month-label');
        const $modalProjectList = $('#modal-project-list');
        const $modalTotalAmount = $('#modal-total-amount');

        // Chargement des Prévisions de Livraison
        function loadDeliveryForecast() {
            $forecastBody.html('<tr><td colspan="3">Chargement des prévisions de livraison...</td></tr>');
            
            $.post(ajaxurl, {
                action: 'ispag_pd_get_delivery_forecast',
                nonce: nonce
            }, function(response) {
                if (response.success) {
                    $forecastBody.empty();
                    response.data.forEach(item => {
                        // Utiliser la fonction formatCurrencyWithDecimals pour plus de précision ici
                        const formattedAmount = formatCurrencyWithDecimals(item.total_amount); 
                        const row = `
                            <tr>
                                <td>${item.month_label}</td>
                                <td style="text-align: right;" class="clickable-amount" 
                                    data-start="${item.start_timestamp}" 
                                    data-end="${item.end_timestamp}" 
                                    data-label="${item.month_label}"
                                    data-total="${item.total_amount}">
                                    ${formattedAmount}
                                </td>
                                <td><button class="button button-small detail-button" 
                                    data-start="${item.start_timestamp}" 
                                    data-end="${item.end_timestamp}" 
                                    data-label="${item.month_label}">Détails</button>
                                </td>
                            </tr>
                        `;
                        $forecastBody.append(row);
                    });
                } else {
                    $forecastBody.html('<tr><td colspan="3">Erreur lors du chargement des prévisions.</td></tr>');
                }
            }).fail(function() {
                 $forecastBody.html('<tr><td colspan="3">Erreur de connexion AJAX (Delivery Forecast).</td></tr>');
            });
        }

        // Chargement des Projets à Facturer
        function loadProjectsToInvoice() {
            $invoiceBody.html('<tr><td colspan="2">Chargement des projets à facturer...</td></tr>');
            
            $.post(ajaxurl, {
                action: 'ispag_pd_get_projects_to_invoice',
                nonce: nonce
            }, function(response) {
                if (response.success) {
                    $invoiceBody.empty();
                    if (response.data.length === 0) {
                        $invoiceBody.html('<tr><td colspan="2">🎉 Rien à facturer pour l\'instant !</td></tr>');
                        return;
                    }
                    
                    response.data.forEach(project => {
                        const projectLink = PROJECT_URL_BASE + project.deal_id;
                        const row = `
                            <tr>
                                <td>
                                    <a href="${projectLink}" target="_blank">${project.project_name} (Deal ID: ${project.deal_id})</a>
                                </td>
                                <td style="text-align: right;">${formatCurrencyWithDecimals(project.total_amount)}</td>
                            </tr>
                        `;
                        $invoiceBody.append(row);
                    });
                } else {
                    $invoiceBody.html('<tr><td colspan="2">Erreur lors du chargement des projets.</td></tr>');
                }
            }).fail(function() {
                 $invoiceBody.html('<tr><td colspan="2">Erreur de connexion AJAX (Projects To Invoice).</td></tr>');
            });
        }

        // Gestion de la modal de LIVRAISON
        $(document).on('click', '#forecast-body .detail-button, #forecast-body .clickable-amount', function() {
            const start = $(this).data('start');
            const end = $(this).data('end');
            const label = $(this).data('label');
            const total = $(this).data('total');

            $modalMonthLabel.text(label);
            $modalTotalAmount.text(formatCurrencyWithDecimals(total));
            
            // Structure de la modal pour l'affichage par projet (Livraison)
            $('#modal-content-data table thead').html(`
                <tr>
                    <th>Projet</th>
                    <th style="text-align: right;">Montant Reste à Livrer (CHF)</th>
                </tr>
            `);
            
            $modalProjectList.html('<tr><td colspan="2">Chargement des détails...</td></tr>');
            $modal.fadeIn();

            // Appel AJAX pour les détails de livraison
            $.post(ajaxurl, {
                action: 'ispag_pd_get_delivery_details',
                nonce: nonce,
                start_timestamp: start,
                end_timestamp: end
            }, function(response) {
                if (response.success) {
                    $modalProjectList.empty();
                    if (response.data.length === 0) {
                        $modalProjectList.html('<tr><td colspan="2">Aucun projet à livrer sur cette période.</td></tr>');
                        return;
                    }

                    let total_verified = 0; 

                    response.data.forEach(project => {
                        const projectLink = PROJECT_URL_BASE + project.deal_id;
                        total_verified += project.total_amount;
                        
                        const row = `
                            <tr>
                                <td>
                                    <a href="${projectLink}" target="_blank">${project.project_name} (Deal ID: ${project.deal_id})</a>
                                </td>
                                <td style="text-align: right; font-weight: bold;">${project.total_amount_formatted}</td>
                            </tr>
                        `;
                        $modalProjectList.append(row);
                    });
                    
                    $modalTotalAmount.text(formatCurrencyWithDecimals(total_verified)); 

                } else {
                    $modalProjectList.html('<tr><td colspan="2">Erreur lors du chargement des détails de livraison.</td></tr>');
                }
            }).fail(function() {
                 $modalProjectList.html('<tr><td colspan="2">Erreur de connexion AJAX (Delivery Details).</td></tr>');
            });
        });

        // Fermeture de la modal (partagée)
        $('.close-button').on('click', function() {
            $modal.fadeOut();
        });
        $(window).on('click', function(event) {
            if (event.target == $modal[0]) {
                $modal.fadeOut();
            }
        });

        // Lancement des chargements initiaux pour la page principale
        loadDeliveryForecast();
        loadProjectsToInvoice();
    }
    
    
    // --- PAGE 2 : ANALYSE DES COMMANDES ---
    
    let monthlyChartInstance = null;
    let cumulativeChartInstance = null;
    
    /**
     * Crée le graphique en barres (Mensuel) pour les Commandes
     */
    function createMonthlyChart(data) {
        if (monthlyChartInstance) monthlyChartInstance.destroy();

        const ctx = document.getElementById('monthly-intake-chart').getContext('2d');
        
        monthlyChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.map(item => item.label),
                datasets: [
                    {
                        label: 'Commandes Reçues (CHF)',
                        data: data.map(item => item.intake),
                        backgroundColor: 'rgba(54, 162, 235, 0.7)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Objectif (CHF)',
                        data: data.map(item => item.target),
                        type: 'line',
                        backgroundColor: 'rgba(255, 99, 132, 0.9)',
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: 2,
                        tension: 0.3
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    label += formatCurrency(context.parsed.y);
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Montant (CHF)'
                        },
                        ticks: {
                             callback: function(value, index, ticks) {
                                 return formatCurrency(value);
                            }
                        }
                    }
                }
            }
        });
    }

    let monthlyInvoiceChartInstance = null;
    let cumulativeInvoiceChartInstance = null;

    /**
     * Crée le graphique en barres (Mensuel) pour la Facturation.
     */
    function createMonthlyInvoiceChart(data) {
        if (monthlyInvoiceChartInstance) monthlyInvoiceChartInstance.destroy();

        const ctx = document.getElementById('monthly-invoice-chart').getContext('2d');

        monthlyInvoiceChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.map(item => item.label),
                datasets: [
                    {
                        label: 'Facturé/Livré (CHF)',
                        data: data.map(item => item.intake),
                        backgroundColor: 'rgba(255, 159, 64, 0.7)', // Orange
                        borderColor: 'rgba(255, 159, 64, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Objectif (CHF)',
                        data: data.map(item => item.target),
                        type: 'line',
                        backgroundColor: 'rgba(255, 99, 132, 0.9)', 
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: 2,
                        tension: 0.3
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) { label += ': '; }
                                if (context.parsed.y !== null) {
                                    label += formatCurrency(context.parsed.y);
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Montant (CHF)' },
                        ticks: {
                             callback: function(value, index, ticks) {
                                 return formatCurrency(value);
                            }
                        }
                    }
                }
            }
        });
    }

    /**
     * Crée le graphique en ligne (Cumulatif) pour les Commandes
     */
    function createCumulativeChart(data) {
        if (cumulativeChartInstance) cumulativeChartInstance.destroy();

        const ctx = document.getElementById('cumulative-intake-chart').getContext('2d');
        
        cumulativeChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.map(item => item.label),
                datasets: [
                    {
                        label: 'Cumul Commandes (CHF)',
                        data: data.map(item => item.intake),
                        borderColor: 'rgba(75, 192, 192, 1)',
                        backgroundColor: 'rgba(75, 192, 192, 0.5)',
                        fill: false,
                        tension: 0.1,
                        pointRadius: 5
                    },
                    {
                        label: 'Cumul Objectif (CHF)',
                        data: data.map(item => item.target),
                        borderColor: 'rgba(153, 102, 255, 1)',
                        backgroundColor: 'rgba(153, 102, 255, 0.5)',
                        fill: false,
                        tension: 0.1,
                        pointStyle: 'cross',
                        pointRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) { label += ': '; }
                                if (context.parsed.y !== null) {
                                    label += formatCurrency(context.parsed.y);
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Montant Cumulé (CHF)' },
                        ticks: {
                             callback: function(value, index, ticks) {
                                 return formatCurrency(value);
                            }
                        }
                    }
                }
            }
        });
    }


    /**
     * Crée le graphique en ligne (Cumulatif) pour la Facturation
     */
    function createCumulativeInvoiceChart(data) {
        if (cumulativeInvoiceChartInstance) cumulativeInvoiceChartInstance.destroy();

        const ctx = document.getElementById('cumulative-invoice-chart').getContext('2d');

        cumulativeInvoiceChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.map(item => item.label),
                datasets: [
                    {
                        label: 'Cumul Facturé (CHF)',
                        data: data.map(item => item.intake),
                        borderColor: 'rgba(50, 200, 50, 1)', // Vert
                        backgroundColor: 'rgba(50, 200, 50, 0.5)', 
                        fill: false,
                        tension: 0.1,
                        pointRadius: 5
                    },
                    {
                        label: 'Cumul Objectif (CHF)',
                        data: data.map(item => item.target),
                        borderColor: 'rgba(153, 102, 255, 1)',
                        backgroundColor: 'rgba(153, 102, 255, 0.5)',
                        fill: false,
                        tension: 0.1,
                        pointStyle: 'cross',
                        pointRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) { label += ': '; }
                                if (context.parsed.y !== null) {
                                    label += formatCurrency(context.parsed.y);
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Montant Cumulé (CHF)' },
                        ticks: {
                             callback: function(value, index, ticks) {
                                 return formatCurrency(value);
                            }
                        }
                    }
                }
            }
        });
    }

    /**
     * Charge et affiche toutes les statistiques de commandes.
     */
    function loadOrderIntakeStats(year) {
        const $monthlyBody = $('#monthly-intake-body');
        const $cumulativeBody = $('#cumulative-intake-body');

        $monthlyBody.html('<tr><td colspan="4">Chargement des données mensuelles...</td></tr>');
        $cumulativeBody.html('<tr><td colspan="4">Chargement des données cumulées...</td></tr>');
        
        $.post(ajaxurl, {
            action: 'ispag_pd_get_order_intake_stats',
            nonce: nonce,
            year: year
        }, function(response) {
            if (response.success) {
                const monthlyData = response.data.monthly;
                const cumulativeData = response.data.cumulative;

                // 1. Mise à jour du Tableau Mensuel
                $monthlyBody.empty();
                monthlyData.forEach(item => {
                    const diff = item.target > 0 ? ((item.intake / item.target) - 1) * 100 : (item.intake > 0 ? 100 : 0);
                    const diffClass = diff >= 0 ? 'color: green;' : 'color: red;';
                    const rowClass = item.is_past ? '' : 'style="background-color: #f7f7f7;"'; 

                    $monthlyBody.append(`
                        <tr ${rowClass} 
                            class="order-detail-row" 
                            data-month="${item.month}" 
                            data-year="${year}" 
                            data-label="${item.label}" 
                            data-total="${item.intake}">
                            <td>${item.label}</td>
                            <td style="text-align: right;">${formatCurrency(item.intake)}</td>
                            <td style="text-align: right;">${formatCurrency(item.target)}</td>
                            <td style="text-align: right; ${diffClass}">${diff.toFixed(1)} %</td>
                        </tr>
                    `);
                });
                
                // 2. Création du Graphique Mensuel
                createMonthlyChart(monthlyData);


                // 3. Mise à jour du Tableau Cumulatif
                $cumulativeBody.empty();
                cumulativeData.forEach(item => {
                    const diff = item.target > 0 ? ((item.intake / item.target) - 1) * 100 : (item.intake > 0 ? 100 : 0);
                    const diffClass = diff >= 0 ? 'color: green;' : 'color: red;';

                    $cumulativeBody.append(`
                        <tr>
                            <td>${item.label}</td>
                            <td style="text-align: right;">${formatCurrency(item.intake)}</td>
                            <td style="text-align: right;">${formatCurrency(item.target)}</td>
                            <td style="text-align: right; ${diffClass}">${diff.toFixed(1)} %</td>
                        </tr>
                    `);
                });

                // 4. Création du Graphique Cumulatif
                createCumulativeChart(cumulativeData);

            } else {
                $monthlyBody.html('<tr><td colspan="4">Erreur : Impossible de charger les données.</td></tr>');
                $cumulativeBody.html('<tr><td colspan="4">Erreur : Impossible de charger les données.</td></tr>');
            }
        }).fail(function() {
             $monthlyBody.html('<tr><td colspan="4">Erreur de connexion AJAX (Order Intake).</td></tr>');
             $cumulativeBody.html('<tr><td colspan="4">Erreur de connexion AJAX (Order Intake).</td></tr>');
        });
    }

    /**
     * Charge et affiche toutes les statistiques de facturation.
     */
    function loadInvoiceStats(year) {
        const $monthlyBody = $('#monthly-invoice-body');
        const $cumulativeBody = $('#cumulative-invoice-body');

        $monthlyBody.html('<tr><td colspan="4">Chargement des données mensuelles...</td></tr>');
        $cumulativeBody.html('<tr><td colspan="4">Chargement des données cumulées...</td></tr>');

        $.post(ajaxurl, {
            action: 'ispag_pd_get_invoice_stats', 
            nonce: nonce,
            year: year
        }, function(response) {
            if (response.success) {
                const monthlyData = response.data.monthly;
                const cumulativeData = response.data.cumulative;

                // 1. Mise à jour du Tableau Mensuel
                $monthlyBody.empty();
                monthlyData.forEach(item => {
                    const diff = item.target > 0 ? ((item.intake / item.target) - 1) * 100 : (item.intake > 0 ? 100 : 0);
                    const diffClass = diff >= 0 ? 'color: green;' : 'color: red;';
                    const rowClass = item.is_past ? '' : 'style="background-color: #f7f7f7;"'; 

                    // AJOUT des data-attributs pour la modal de facturation
                    $monthlyBody.append(`
                        <tr ${rowClass} 
                            class="invoice-detail-row" 
                            data-month="${item.month}" 
                            data-year="${year}" 
                            data-label="${item.label}" 
                            data-total="${item.intake}">
                            <td>${item.label}</td>
                            <td style="text-align: right;">${formatCurrency(item.intake)}</td>
                            <td style="text-align: right;">${formatCurrency(item.target)}</td>
                            <td style="text-align: right; ${diffClass}">${diff.toFixed(1)} %</td>
                        </tr>
                    `);
                });

                // 2. Création du Graphique Mensuel
                createMonthlyInvoiceChart(monthlyData);

                // 3. Mise à jour du Tableau Cumulatif
                $cumulativeBody.empty();
                cumulativeData.forEach(item => {
                    const diff = item.target > 0 ? ((item.intake / item.target) - 1) * 100 : (item.intake > 0 ? 100 : 0);
                    const diffClass = diff >= 0 ? 'color: green;' : 'color: red;';

                    $cumulativeBody.append(`
                        <tr>
                            <td>${item.label}</td>
                            <td style="text-align: right;">${formatCurrency(item.intake)}</td>
                            <td style="text-align: right;">${formatCurrency(item.target)}</td>
                            <td style="text-align: right; ${diffClass}">${diff.toFixed(1)} %</td>
                        </tr>
                    `);
                });

                // 4. Création du Graphique Cumulatif
                createCumulativeInvoiceChart(cumulativeData);

            } else {
                $monthlyBody.html('<tr><td colspan="4">Erreur : Impossible de charger les données de facturation.</td></tr>');
                $cumulativeBody.html('<tr><td colspan="4">Erreur : Impossible de charger les données de facturation.</td></tr>');
            }
        }).fail(function() {
            $monthlyBody.html('<tr><td colspan="4">Erreur de connexion AJAX (Invoice Stats).</td></tr>');
            $cumulativeBody.html('<tr><td colspan="4">Erreur de connexion AJAX (Invoice Stats).</td></tr>');
        });
    }
    
    // ... (Fonctions createQuotationChart et loadQuotationStats inchangées)
    let quotationChartInstance = null;

    function createQuotationChart(data) {
        if (quotationChartInstance) quotationChartInstance.destroy();

        const ctx = document.getElementById('monthly-quotation-chart').getContext('2d');
        const previousYear = currentYear - 1; // Correction pour utiliser la variable globale si non passée

        quotationChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.map(item => item.label),
                datasets: [
                    {
                        label: `Offres ${currentYear}`,
                        data: data.map(item => item.current_year_quantity),
                        backgroundColor: 'rgba(54, 162, 235, 0.7)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1,
                        yAxisID: 'y'
                    },
                    {
                        label: `Offres ${previousYear}`, 
                        data: data.map(item => item.previous_year_quantity),
                        backgroundColor: 'rgba(255, 99, 132, 0.7)',
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: 1,
                        yAxisID: 'y'
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Nombre d\'Offres' }
                    }
                }
            }
        });
    }

    function loadQuotationStats(year) {
        const $monthlyBody = $('#monthly-quotation-body');

        $monthlyBody.html('<tr><td colspan="2">Chargement des données d\'offres...</td></tr>');

        $.post(ajaxurl, {
            action: 'ispag_pd_get_quotation_stats', 
            nonce: nonce,
            year: year
        }, function(response) {
            if (response.success) {
                const monthlyData = response.data;
                const previousYear = year - 1; 

                // 1. Mise à jour du Tableau Mensuel
                $monthlyBody.empty();
                monthlyData.forEach(item => {
                    const evolution = item.previous_year_quantity > 0 
                        ? ((item.current_year_quantity - item.previous_year_quantity) / item.previous_year_quantity) * 100
                        : (item.current_year_quantity > 0 ? 100 : 0);
                    
                    const evolutionText = evolution.toFixed(1) + '%';
                    const evolutionClass = evolution > 0 ? 'color: green;' : (evolution < 0 ? 'color: red;' : '');
                    
                    const rowClass = item.is_past ? '' : 'style="background-color: #f7f7f7;"'; 

                    $monthlyBody.append(`
                        <tr ${rowClass}>
                            <td>${item.label}</td>
                            <td style="text-align: right;">${item.current_year_quantity}</td>
                            <td style="text-align: right;">${item.previous_year_quantity}</td>
                            <td style="text-align: right; ${evolutionClass}">${evolutionText}</td>
                        </tr>
                    `);
                });

                // 2. Création du Graphique Mensuel
                createQuotationChart(monthlyData, year, previousYear); 

            } else {
                $monthlyBody.html('<tr><td colspan="2">Erreur : Impossible de charger les données d\'offres.</td></tr>');
            }
        }).fail(function() {
            $monthlyBody.html('<tr><td colspan="2">Erreur de connexion AJAX (Quotation Stats).</td></tr>');
        });
    }

    // --- LOGIQUE MODAL DÉTAILS FACTURATION (LA NOUVELLE FONCTION DEMANDÉE) ---

    /**
     * Charge les détails facturés pour le mois sélectionné et affiche la modal.
     * @param {number} month Numéro du mois (1-12).
     * @param {string} monthLabel Nom du mois.
     * @param {number} year Année.
     * @param {number} total Total facturé pour ce mois (pour affichage initial).
     */
    function showInvoicedDetailsModal(month, monthLabel, year, total) {
        const $modal = $('#ispag-pd-modal');
        const $modalMonthLabel = $('#modal-month-label');
        const $modalProjectList = $('#modal-project-list');
        const $modalTotalAmount = $('#modal-total-amount');

        // Initialisation de la modal
        $modalMonthLabel.text(monthLabel + ' ' + year);
        $modalTotalAmount.text(formatCurrency(total));
        
        // Structure de la modal pour l'affichage des Projets Facturés
        $('#modal-content-data table thead').html(`
            <tr>
                <th>Projet</th>
                <th>Statut Facture</th>
                <th style="text-align: right;">Montant Facturé (CHF)</th>
            </tr>
        `);

        $modalProjectList.html('<tr><td colspan="3">Chargement des détails de facturation...</td></tr>');
        $modal.fadeIn();

        $.post(ajaxurl, {
            action: 'ispag_pd_get_invoiced_details', // NOUVELLE ACTION AJAX
            nonce: nonce,
            month: month,
            year: year
        }, function(response) {
            if (response.success) {
                const data = response.data;
                $modalProjectList.empty();
                
                if (data.projects.length === 0) {
                     $modalProjectList.html('<tr><td colspan="3">Aucun projet facturé ce mois-ci.</td></tr>');
                } else {
                    data.projects.forEach(project => {
                        const projectLink = PROJECT_URL_BASE + project.deal_id;
                        $modalProjectList.append(`
                            <tr>
                                <td>
                                    <a href="${projectLink}" target="_blank">${project.project_name} (${project.articles_count} article(s))</a>
                                </td>
                                <td>—</td> 
                                <td style="text-align: right; font-weight: bold;">${formatCurrencyWithDecimals(project.total_amount)}</td>
                            </tr>
                        `);
                    });
                    
                    // Mise à jour finale du total (au cas où il y aurait une différence avec le total du tableau)
                    $modalTotalAmount.text(formatCurrencyWithDecimals(data.total_amount));
                }
            } else {
                 $modalProjectList.html(`<tr><td colspan="3">Erreur lors du chargement des détails: ${response.data.message || 'Erreur inconnue'}</td></tr>`);
            }
        }).fail(function() {
             $modalProjectList.html('<tr><td colspan="3">Erreur de connexion AJAX (Invoiced Details).</td></tr>');
        });
    }

    // --- LOGIQUE D'INITIALISATION GLOBALE ET GESTIONNAIRES D'ÉVÉNEMENTS ---
    
    const urlParams = new URLSearchParams(window.location.search);
    const pageSlug = urlParams.get('page');

    if (pageSlug === 'ispag-project-dashboard') {
        initProjectDashboard();
    } else if (pageSlug === 'ispag-order-stats') {
        loadOrderIntakeStats(currentYear);
    } else if (pageSlug === 'ispag-invoice-stats') { 
        loadInvoiceStats(currentYear);
    } else if (pageSlug === 'ispag-quotation-stats') {
        loadQuotationStats(currentYear);
    }
    
    // GESTIONNAIRE DE CLIC pour les détails de COMMANDE (Page 'ispag-order-stats')
    $(document).on('click', '.order-detail-row', function() { 
        const month = $(this).data('month');
        const year = $(this).data('year');
        const label = $(this).data('label');
        const total = $(this).data('total');
        
        // Réutilisation de la même logique que celle de la fonction showInvoicedDetailsModal
        const $modal = $('#ispag-pd-modal');
        const $modalMonthLabel = $('#modal-month-label');
        const $modalProjectList = $('#modal-project-list');
        const $modalTotalAmount = $('#modal-total-amount');

        $modalMonthLabel.text(label + ' ' + year);
        $modalTotalAmount.text(formatCurrency(total));
        
        // Structure de la modal pour l'affichage par projet (Order Intake)
        $('#modal-content-data table thead').html(`
            <tr>
                <th>Projet</th>
                <th style="text-align: right;">Montant Commandé (CHF)</th>
            </tr>
        `);
        
        $modalProjectList.html('<tr><td colspan="2">Chargement des détails de commandes...</td></tr>');
        $modal.fadeIn();

        // Appel AJAX pour les détails de la commande
        $.post(ajaxurl, {
            action: 'ispag_pd_get_order_details',
            nonce: nonce,
            year: year,
            month: month
        }, function(response) {
            if (response.success) {
                const projects  = response.data;
                $modalProjectList.empty();
                // CONSEIL: Ajoutez un console.log pour vérifier les données reçues
//                console.log('Données reçues (ispag_pd_get_order_details):', projects );
                
                if (projects.length === 0) {
                    $modalProjectList.html('<tr><td colspan="3">Aucun projet facturé ce mois-ci.</td></tr>');
                    // On sort si vide, le total initial est déjà affiché (et correct si la base est bonne)
                } else{
                    let total_verified = 0;
                    projects.forEach(project => {
                        
                        const projectLink = PROJECT_URL_BASE + project.deal_id; // OK (clé ajoutée en PHP)
                        // // On agrège le montant pour s'assurer que le total affiché est le bon
                        total_verified += parseFloat(project.total_amount); // OK (clé renommée en PHP)

                        $modalProjectList.append(`
                            <tr>
                                <td>
                                    <a href="${projectLink}" target="_blank">${project.project_name} (ID: ${project.deal_id})</a>
                                </td>
                                
                                <td style="text-align: right; font-weight: bold;">${formatCurrencyWithDecimals(project.total_amount)}</td>
                            </tr>
                        `);
                    });
                    $modalTotalAmount.text(formatCurrencyWithDecimals(total_verified)); 
                }

            } else {
                 $modalProjectList.html('<tr><td colspan="2">Erreur lors du chargement des détails de commandes.</td></tr>');
            }
        }).fail(function() {
             $modalProjectList.html('<tr><td colspan="2">Erreur de connexion AJAX (Order Details).</td></tr>');
        });
    });

    // GESTIONNAIRE DE CLIC pour les détails de FACTURATION (Page 'ispag-invoice-stats')
    $(document).on('click', '.invoice-detail-row', function() {
        const month = $(this).data('month');
        const year = $(this).data('year');
        const label = $(this).data('label');
        const total = $(this).data('total');

        if (month && year) {
            showInvoicedDetailsModal(month, label, year, total);
        }
    });

});

// GESTIONNAIRE DE FERMETURE DE LA MODAL
$(document).on('click', '#ispag-pd-modal .modal-close, #ispag-pd-modal .modal-backdrop', function(e) {
    // Empêche la fermeture si on clique accidentellement sur le contenu de la modal,
    // mais permet la fermeture sur l'arrière-plan ou le bouton.
    if ($(e.target).hasClass('modal-close') || $(e.target).hasClass('modal-backdrop') || $(e.target).closest('.modal-content').length === 0) {
        
        // Cible la modal par son ID
        $('#ispag-pd-modal').fadeOut(); 
    }
});

// Facultatif mais recommandé : Fermeture avec la touche Échap
$(document).on('keyup', function(e) {
    if (e.key === "Escape" || e.keyCode === 27) {
        $('#ispag-pd-modal').fadeOut();
    }
});
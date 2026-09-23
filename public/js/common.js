// common.js - Fonctions utilitaires partagées

/**
 * Affiche/masque un indicateur de chargement
 * @param {HTMLElement} element - L'élément à afficher/masquer
 * @param {boolean} show - true pour afficher, false pour masquer
 */
function toggleLoading(element, show = true) {
    if (element) {
        if (show) {
            element.classList.remove('hidden');
        } else {
            element.classList.add('hidden');
        }
    }
}

/**
 * Met à jour l'URL avec les paramètres de recherche sans recharger la page
 * @param {Object} params - Les paramètres à ajouter/supprimer de l'URL
 */
function updateURL(params) {
    const url = new URL(window.location);
    
    Object.keys(params).forEach(key => {
        if (params[key]) {
            url.searchParams.set(key, params[key]);
        } else {
            url.searchParams.delete(key);
        }
    });
    
    window.history.pushState({}, '', url);
}

/**
 * Anime l'apparition des nouvelles cartes avec un effet de fondu
 * @param {HTMLElement} container - Le conteneur des cartes
 */
function animateNewCards(container) {
    const cards = container.querySelectorAll(':scope > div');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            card.style.transition = 'all 0.3s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 50);
    });
}

/**
 * Affiche un message d'erreur dans une grille
 * @param {HTMLElement} grid - La grille où afficher l'erreur
 * @param {string} message - Le message d'erreur à afficher
 */
function showError(grid, message = 'Une erreur est survenue') {
    if (grid) {
        grid.innerHTML = `
            <div class="col-span-full text-center py-12">
                <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-exclamation-triangle text-2xl text-red-600"></i>
                </div>
                <p class="text-lg text-red-600">${message}</p>
                <p class="text-sm text-slate-500">Veuillez réessayer plus tard</p>
                <button onclick="location.reload()" class="mt-4 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                    Réessayer
                </button>
            </div>
        `;
    }
}

/**
 * Formate un nombre avec des séparateurs de milliers
 * @param {number} number - Le nombre à formater
 * @returns {string} Le nombre formaté
 */
function formatNumber(number) {
    return new Intl.NumberFormat('fr-FR').format(number);
}

/**
 * Tronque un texte à une longueur maximale
 * @param {string} text - Le texte à tronquer
 * @param {number} length - La longueur maximale
 * @returns {string} Le texte tronqué
 */
function truncateText(text, length = 50) {
    if (!text) return '';
    return text.length > length ? text.substring(0, length) + '...' : text;
}

/**
 * Débounce pour limiter les appels de fonction (ex: recherche)
 * @param {Function} func - La fonction à exécuter
 * @param {number} wait - Le temps d'attente en ms
 * @returns {Function} La fonction avec débounce
 */
function debounce(func, wait = 300) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Exposer les fonctions globalement pour les utiliser dans d'autres scripts
window.toggleLoading = toggleLoading;
window.updateURL = updateURL;
window.animateNewCards = animateNewCards;
window.showError = showError;
window.formatNumber = formatNumber;
window.truncateText = truncateText;
window.debounce = debounce;
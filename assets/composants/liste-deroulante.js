/*
 * Liste déroulante avec recherche intégrée (comme Select2 sur e-justice.ci),
 * en Alpine + Tailwind. Sert à la nationalité et à l'indicatif des numéros de
 * téléphone.
 *
 * Balisage attendu, dans l'élément x-data="listeDeroulante" (positionné) :
 *   x-ref="natif"      la vraie <select> : source des options et valeur envoyée
 *                      (masquée au démarrage ; sans JavaScript, elle reste seule)
 *   x-ref="bouton"     le bouton qui ouvre la liste
 *   x-ref="recherche"  le champ de recherche du panneau
 *   x-ref="liste"      la <ul role="listbox">
 *   x-ref="apresChoix" (facultatif) reçoit le focus après un choix
 *
 * Libellés des options : « Malienne · Mali » ou « Côte d'Ivoire (+225) » ; un
 * attribut data-court donne le texte du bouton (« +225 »).
 */
export function libelleOption(option) {
    const texte = option.text.trim();
    const indicatif = /^(.*) \((\+\d+)\)$/.exec(texte);
    const [principal, secondaire] = indicatif ? [indicatif[1], indicatif[2]] : texte.split(' · ');

    return {
        valeur: option.value,
        principal,
        secondaire: secondaire || '',
        court: option.dataset.court || texte,
        texte,
    };
}

export function normaliser(texte) {
    return String(texte || '').normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase().trim();
}

let compteur = 0;

export default function listeDeroulante() {
    return {
        ouverte: false,
        q: '',
        actif: 0,
        valeur: '',
        options: [],
        hauteurListe: 256,
        prefixe: '',
        vide: 'Sélectionnez',

        init() {
            const natif = this.$refs.natif;
            this.prefixe = 'liste-deroulante-' + (++compteur);
            natif.hidden = true;
            this.valeur = natif.value;
            // Texte du bouton tant que rien n'est choisi : celui de l'option vide.
            const optionVide = Array.from(natif.options).find((o) => o.value === '');
            if (optionVide) { this.vide = optionVide.text; }
            this.options = Array.from(natif.options).filter((o) => o.value !== '').map(libelleOption);
            // Le clavier du téléphone réduit la zone visible : la liste suit.
            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', () => { if (this.ouverte) { this.ajuster(); } });
            }
        },

        get choisie() {
            return this.options.find((o) => o.valeur === this.valeur) || null;
        },

        get filtrees() {
            const q = normaliser(this.q);
            return q === '' ? this.options : this.options.filter((o) => normaliser(o.texte).includes(q));
        },

        idOption(rang) {
            return this.prefixe + '-option-' + rang;
        },

        ouvrir() {
            this.q = '';
            this.ouverte = true;
            this.actif = Math.max(0, this.filtrees.findIndex((o) => o.valeur === this.valeur));
            // Le champ remonte juste sous l'en-tête fixe : la liste et sa recherche
            // restent visibles au-dessus du clavier qui va s'ouvrir.
            const entete = document.querySelector('header');
            const marge = (entete ? entete.getBoundingClientRect().bottom : 0) + 12;
            const haut = this.$refs.bouton.getBoundingClientRect().top;
            if (haut < marge || haut > window.innerHeight * 0.35) {
                window.scrollTo({ top: window.scrollY + haut - marge, behavior: 'auto' });
            }
            this.$nextTick(() => {
                this.$refs.recherche.focus({ preventScroll: true });
                this.ajuster();
                this.voir();
            });
        },

        fermer(rendreFocus) {
            if (!this.ouverte) { return; }
            this.ouverte = false;
            if (rendreFocus) { this.$refs.bouton.focus(); }
        },

        // Ferme la liste quand le focus quitte le composant (touche Tab).
        quitter(evenement) {
            if (!this.$el.contains(evenement.relatedTarget)) { this.fermer(false); }
        },

        choisir(option) {
            this.valeur = option.valeur;
            this.$refs.natif.value = option.valeur;
            this.$refs.natif.dispatchEvent(new Event('change', { bubbles: true }));
            this.ouverte = false;
            (this.$refs.apresChoix || this.$refs.bouton).focus();
        },

        choisirActif() {
            if (this.filtrees[this.actif]) { this.choisir(this.filtrees[this.actif]); }
        },

        deplacer(sens) {
            const n = this.filtrees.length;
            if (n === 0) { return; }
            this.actif = (this.actif + sens + n) % n;
            this.voir();
        },

        // Hauteur de la liste : l'espace visible sous elle, entre 132 et 256 px.
        ajuster() {
            const vv = window.visualViewport;
            const zone = vv ? vv.height + vv.offsetTop : window.innerHeight;
            const haut = this.$refs.liste.getBoundingClientRect().top;
            this.hauteurListe = Math.max(132, Math.min(256, Math.floor(zone - haut - 12)));
        },

        // Garde l'option active visible en ne faisant défiler que la liste.
        voir() {
            this.$nextTick(() => {
                const li = document.getElementById(this.idOption(this.actif));
                const liste = this.$refs.liste;
                if (!li) { return; }
                if (li.offsetTop < liste.scrollTop) {
                    liste.scrollTop = li.offsetTop;
                } else if (li.offsetTop + li.offsetHeight > liste.scrollTop + liste.clientHeight) {
                    liste.scrollTop = li.offsetTop + li.offsetHeight - liste.clientHeight;
                }
            });
        },
    };
}

/*
 * Mise en forme d'un numéro pendant la frappe, selon le pays choisi :
 * Côte d'Ivoire, 10 chiffres par paires (« 07 08 09 10 11 ») ; ailleurs,
 * chiffres groupés par deux depuis la fin, 13 au plus.
 */
export function formaterNumero(valeur, pays) {
    let chiffres = String(valeur || '').replace(/\D/g, '');
    if (pays === 'CI') {
        // Numéro collé avec l'indicatif (+225 07…) : l'indicatif est déjà choisi.
        if (chiffres.length > 10 && chiffres.indexOf('225') === 0) { chiffres = chiffres.slice(3); }
        return chiffres.slice(0, 10).replace(/(\d{2})(?=\d)/g, '$1 ');
    }
    chiffres = chiffres.slice(0, 14);
    return chiffres.split('').reverse().join('').replace(/(\d{2})(?=\d)/g, '$1 ').split('').reverse().join('');
}

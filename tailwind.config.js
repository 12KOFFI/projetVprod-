const {
  inherit,
  current,
  transparent,
  black,
  white,
  slate,
  gray,
  zinc,
  neutral,
  stone,
  red,
  orange,
  amber,
  yellow,
  lime,
  green,
  emerald,
  teal,
  cyan,
  sky,
  pink,
  rose,
  fuchsia,
} = require('tailwindcss/colors');

// Bleu DAIP : le 900 reprend le bleu marine dominant du logo (#012F5F).
const daipBlue = {
  50: '#F2F8FC',
  100: '#DEEFF8',
  200: '#B9DDED',
  300: '#8DC4DE',
  400: '#5FA6C9',
  500: '#2D83B4',
  600: '#0B669D',
  700: '#075786',
  800: '#03476F',
  900: '#012F5F',
  950: '#001D3D',
};

// Variante plus douce pour les dégradés et les surfaces secondaires.
const daipBlueSoft = {
  50: '#F1F8FB',
  100: '#DBEEF5',
  200: '#B6DDEB',
  300: '#85C1DA',
  400: '#4A9FBE',
  500: '#217FA5',
  600: '#096B96',
  700: '#075878',
  800: '#05465F',
  900: '#023648',
  950: '#012530',
};

/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ["./templates/**/*.html.twig", "./assets/**/*.js"],
  theme: {
    // Les classes historiques sont conservées dans les gabarits, mais leur
    // rendu devient bleu. Les couleurs sémantiques restent inchangées.
    colors: {
      inherit,
      current,
      transparent,
      black,
      white,
      slate,
      gray,
      zinc,
      neutral,
      stone,
      red,
      orange,
      amber,
      yellow,
      lime,
      green,
      emerald,
      teal,
      cyan,
      sky,
      pink,
      rose,
      fuchsia,
      blue: daipBlue,
      indigo: daipBlue,
      violet: daipBlueSoft,
      purple: daipBlueSoft,
      primary: daipBlue,
      secondary: daipBlueSoft,
    },
    extend: {
      transitionTimingFunction: {
        // Décélération quasi exponentielle : l'élément arrive vite puis se pose.
        // Déclarée ici, et non en CSS inline, pour que l'utilitaire généré
        // remporte la cascade face à la courbe par défaut de `.transition`.
        expo: "cubic-bezier(0.16, 1, 0.3, 1)",
      },
      animation: {
        scroll: "scroll 30s linear infinite",
        "scroll-reverse": "scroll 30s linear infinite reverse",
        "fade-in": "fadeIn 0.5s ease-in-out",
        "slide-up": "slideUp 0.6s ease-out",
      },
      keyframes: {
        scroll: {
          "0%": { transform: "translateX(0)" },
          "100%": { transform: "translateX(-50%)" },
        },
        fadeIn: { "0%": { opacity: "0" }, "100%": { opacity: "1" } },
        slideUp: {
          "0%": { transform: "translateY(20px)", opacity: "0" },
          "100%": { transform: "translateY(0)", opacity: "1" },
        },
      },
    },
  },
};

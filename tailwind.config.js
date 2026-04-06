import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            colors: {
                ink: {
                    DEFAULT: '#09090b',
                    soft: '#18181b',
                    muted: '#27272a',
                },
                champagne: {
                    DEFAULT: '#f7f3eb',
                    dim: '#e8dfd0',
                },
                cream: '#f0ebe3',
                gold: {
                    DEFAULT: '#c4a574',
                    bright: '#dcc598',
                    muted: '#8f7a56',
                },
            },
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
                display: ['Cormorant Garamond', 'Georgia', 'serif'],
                luxury: ['Plus Jakarta Sans', ...defaultTheme.fontFamily.sans],
            },
            keyframes: {
                luxuryReveal: {
                    '0%': { opacity: '0', transform: 'translateY(14px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
            },
            animation: {
                'luxury-reveal': 'luxuryReveal 0.85s ease-out forwards',
            },
        },
    },

    plugins: [forms],
};

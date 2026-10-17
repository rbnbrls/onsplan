import { SVGAttributes } from 'react';
import AppLogoIcon from './app-logo-icon';

// Brand lockup: yellow tile + dark leaf and the onsplan wordmark.
export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-[#FFDF00]">
                <AppLogoIcon className="size-5 fill-[#101100]" />
            </div>
            <div className="ml-1 flex flex-1 items-center">
                <AppWordmark className="h-4 w-auto fill-current" />
            </div>
        </>
    );
}

// Keep the wordmark as SVG so it inherits the surrounding text colour in light and dark themes.
export function AppWordmark(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 240 56" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="onsplan">
            <text x="0" y="43" fill="currentColor" fontFamily="Inter, Arial, sans-serif" fontSize="42" fontWeight="600" letterSpacing="-1.5">
                onsplan
            </text>
        </svg>
    );
}

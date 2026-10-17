import { SVGAttributes } from 'react';

// onsplan leaf logomark (filled path, works with fill-current styling).
// Source of truth: onsplan-private design/assets/logo/onsplan Exports/onsplan-Glyph.svg.
// The glyph is 108.68x100.77; the viewBox is padded to a square (y -3.955) so it centres in size-* boxes.
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 -3.955 108.68 108.68" xmlns="http://www.w3.org/2000/svg">
            <path d="M82,25.48C35.1,35.89,22.5,67.11,11.53,94.46c-.83,2.08.24,4.43,2.35,5.17l2.37.83c2.44.85,4.69-.16,5.52-2.17l1.67-4.04c1.09-2.65,3.9-4.23,6.71-3.68.98.19,1.88.3,2.64.3C86.81,90.88,105.45,15.92,108.67.74c.13-.61-.6-.99-1.04-.55-9.1,9-43.99,10.56-69.43,15.59C10.9,21.18,0,44.48,0,55.38c0,8.18,5.41,15.63,8.11,18.84.65.78,1.89.54,2.25-.4C28.8,25.38,81.9,25.38,81.9,25.38l.1.1Z" />
        </svg>
    );
}

import { useBrand } from '../context/BrandContext';

/** Custom logo when one is set, else the glyph box. */
export default function BrandMark({ glyph = 'S', box = 'w-12 h-12 rounded-xl bg-brand-600 text-white text-2xl font-bold', img = 'w-12 h-12 rounded-xl object-contain bg-white border' }) {
  const { logoUrl } = useBrand();
  if (logoUrl) return <img src={logoUrl} alt="" className={`${img} mb-4`} />;
  return <div className={`${box} flex items-center justify-center mb-4`}>{glyph}</div>;
}

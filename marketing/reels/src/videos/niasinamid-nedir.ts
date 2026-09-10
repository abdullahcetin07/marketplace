import type { VideoScript } from './types';

/**
 * Faz 2a pilot — S2 ("Ne İşe Yarar"). Deliberately chosen as the TTS stress
 * test: a foreign-origin ingredient name, percentages read aloud, and copy that
 * sits right on the health-claim line without crossing it.
 */
export const niasinamidNedir: VideoScript = {
  id: 'niasinamid-nedir',
  series: 'S2',
  query: 'niasinamid ne işe yarar',
  targetUrl: '/urunler?q=niasinamid',
  title: 'Niasinamid Nedir, Hangi Ürünlerde Bulunur?',
  scenes: [
    {
      narration:
        'Cilt bakımı ürünlerinin içindekiler listesinde sık sık niacinamide, yani niasinamid yazdığını görüyorsun. Peki bu madde tam olarak ne?',
      onScreen: 'Niasinamid nedir?',
    },
    {
      narration:
        'Niasinamid, B3 vitamininin bir formudur. Suda çözünen bir maddedir ve kozmetik ürünlerde uzun yıllardır kullanılır.',
      onScreen: 'B3 vitamininin bir formu',
    },
    {
      narration:
        'Ürün etiketlerinde genellikle yüzde iki ile yüzde on arasında bir oranla yer alır. Etikette oranı göremiyorsan içindekiler listesindeki sırasına bakabilirsin; liste azalan sırayla yazılır.',
      onScreen: 'Genellikle %2 – %10',
    },
    {
      narration:
        'Üreticiler niasinamidi kozmetik ürünlerde çoğunlukla cildin nem dengesine ve görünümüne katkı sağlamak amacıyla formüle ekler.',
      onScreen: 'Neden ekleniyor?',
    },
    {
      narration:
        'En sık serumlarda ve nemlendiricilerde karşına çıkar. Temizleyicilerde de bulunur, ancak temizleyici ciltte kısa süre kaldığı için serum ve krem formları daha yaygın tercih edilir.',
      onScreen: 'Serum ve nemlendirici',
    },
    {
      narration:
        'Ürün seçerken üç şeye bakmanı öneririz: etiketteki oran, ürünün formu, ve cildinin diğer ihtiyaçlarıyla uyumu.',
      onScreen: 'Seçerken 3 başlık',
    },
    {
      narration:
        'Niasinamid kozmetik bir içeriktir, ilaç değildir. Buradaki bilgi bir tedavi önerisi değildir; cildinle ilgili bir sorun varsa doğru adres bir hekimdir.',
      onScreen: 'Bu bir tedavi önerisi değildir',
      role: 'disclaimer',
    },
    {
      narration:
        'Niasinamid içeren ürünleri Raftabul’da onaylı satıcılardan bulabilirsin. Açıklamadaki bağlantıdan tüm listeye ulaşabilirsin.',
      onScreen: 'raftabul.com',
    },
  ],
  shorts: [[0, 1], [2], [5]],
};

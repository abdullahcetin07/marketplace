/**
 * Static content pages — the footer's "Kurumsal / Yardım / Güven" links.
 *
 * ONE PLACE FOR ALL COPY. Each page is data here and rendered by `/sayfa/[slug]`, so
 * adding or editing a page is a text change in this file, not a new route. The
 * informational pages carry real starter copy (edit freely); the three legal pages
 * (KVKK, Gizlilik, Kullanım Şartları) are STRUCTURED PLACEHOLDERS — the binding text
 * must be supplied and reviewed by a lawyer, and each says so in a `note`.
 *
 * Contact details (e-posta, telefon) are placeholders too — set the real ones here.
 */

export type PageBlock =
  | { h: string }
  | { p: string }
  | { ul: string[] }
  | { qa: [question: string, answer: string] }
  | { note: string };

export type ContentPage = {
  title: string;
  description: string;
  intro?: string;
  body: PageBlock[];
};

const SUPPORT_EMAIL = 'destek@raftabul.com';

export const contentPages: Record<string, ContentPage> = {
  hakkimizda: {
    title: 'Hakkımızda',
    description: 'Raftabul, onaylı eczane ve mağazaların orijinal sağlık, dermokozmetik, vitamin ve kişisel bakım ürünlerini bir araya getiren güvenilir bir pazaryeridir.',
    intro:
      'Raftabul, onaylı eczane ve mağazaların orijinal sağlık, dermokozmetik, vitamin ve kişisel bakım ürünlerini tek çatı altında buluşturan bir pazaryeridir.',
    body: [
      { h: 'Misyonumuz' },
      { p: 'Sağlık ve bakım ürünlerinde alışverişi güvenli, şeffaf ve erişilebilir kılmak istiyoruz. Her ürünün ardında onaylı bir satıcı, her fiyatın ardında açık bir karşılaştırma var.' },
      { h: 'Neden Raftabul?' },
      {
        ul: [
          'Onaylı satıcılar: Mağazalarımız başvuru ve doğrulama sürecinden geçer.',
          'Orijinal ürün güvencesi: Listelenen tüm ürünler orijinaldir.',
          'Şeffaf fiyat: Aynı ürünü satan satıcıları yan yana görür, en uygununu seçersiniz.',
          'Güvenli ödeme: Kart bilgileriniz bize iletilmez, ödeme güvenli altyapı üzerinden alınır.',
          'Kolay iade: 14 gün içinde koşulsuz iade hakkı.',
        ],
      },
      { h: 'Puan kazandıran alışveriş' },
      { p: 'Üye olduğunuzda, alışveriş yaptığınızda ve ürün değerlendirdiğinizde puan kazanır, kazandığınız puanları sonraki alışverişlerinizde indirim olarak kullanırsınız.' },
    ],
  },

  'satici-ol': {
    title: 'Satıcı Ol',
    description: 'Raftabul’da satış yapın: onaylı bir pazaryerinde binlerce müşteriye ulaşın.',
    intro: 'Ürünlerinizi Raftabul’da satmak ve onaylı bir pazaryerinde binlerce müşteriye ulaşmak çok kolay.',
    body: [
      { h: 'Nasıl başvurulur?' },
      {
        ul: [
          'Satıcı hesabı oluşturun ve mağaza bilgilerinizi girin.',
          'Kimlik/işletme ve vergi bilgilerinizi doğrulayın.',
          'Mağazanız onaylandıktan sonra ürünlerinizi listelemeye başlayın.',
        ],
      },
      { h: 'Neden Raftabul’da satmalısınız?' },
      {
        ul: [
          'Hazır müşteri kitlesi ve arama görünürlüğü.',
          'Toplu ürün ve stok yükleme (API + CSV) ile binlerce SKU’yu kolayca yönetin.',
          'Şeffaf komisyon ve düzenli ödeme (payout).',
          'Sipariş, kargo ve iade süreçleri için satıcı paneli.',
        ],
      },
      { note: `Satıcı başvurusu ve detaylı bilgi için ${SUPPORT_EMAIL} adresinden bize ulaşın.` },
    ],
  },

  kariyer: {
    title: 'Kariyer',
    description: 'Raftabul ekibine katılın. Açık pozisyonlar ve başvuru bilgileri.',
    intro: 'Sağlık ve bakım alışverişini yeniden tasarlayan ekibimize katılmak ister misiniz?',
    body: [
      { p: 'Büyüyen bir pazaryeri kuruyoruz ve yolculuğun her aşamasında yetenekli insanlarla çalışmak istiyoruz.' },
      { h: 'Açık pozisyonlar' },
      { p: 'Şu anda yayında açık bir pozisyon bulunmuyor. Yine de kendinize uygun bir rol olduğunu düşünüyorsanız, açık başvuru gönderebilirsiniz.' },
      { note: `Özgeçmişinizi ve birkaç satır kendinizi anlatan notu ${SUPPORT_EMAIL} adresine gönderin.` },
    ],
  },

  iletisim: {
    title: 'İletişim',
    description: 'Raftabul ile iletişime geçin: destek e-postası ve çalışma saatleri.',
    intro: 'Sorularınız, önerileriniz veya bir sorununuz için buradayız.',
    body: [
      { h: 'Müşteri Desteği' },
      { p: `E-posta: ${SUPPORT_EMAIL}` },
      { p: 'Çalışma saatleri: Hafta içi 09:00 – 18:00' },
      { h: 'Siparişinizle mi ilgili?' },
      { p: 'Sipariş durumunuzu, kargo takibinizi ve iade taleplerinizi hesabınızdaki “Siparişlerim” bölümünden takip edebilirsiniz.' },
      { note: 'Gerçek iletişim bilgilerinizi (e-posta, telefon, adres, sosyal medya) bu sayfadan güncelleyin.' },
    ],
  },

  'iade-degisim': {
    title: 'İade & Değişim',
    description: 'Raftabul iade ve değişim koşulları: 14 gün içinde koşulsuz iade.',
    intro: 'Aldığınız üründen memnun kalmadıysanız, iade süreci basit ve nettir.',
    body: [
      { h: 'İade koşulları' },
      {
        ul: [
          'Teslim aldığınız tarihten itibaren 14 gün içinde iade talebi oluşturabilirsiniz.',
          'Ürün kullanılmamış, orijinal ambalajında ve satılabilir durumda olmalıdır.',
          'Bazı hijyen ve kişisel bakım ürünleri, ambalajı açıldıysa iade edilemeyebilir.',
        ],
      },
      { h: 'Nasıl iade edilir?' },
      {
        ul: [
          '“Siparişlerim” sayfasından ilgili siparişi açın.',
          'İade talebini başlatın ve nedenini belirtin.',
          'Satıcı talebinizi onayladığında size bir iade kodu iletilir.',
          'Ürünü kargoya verin; ürün satıcıya ulaşıp onaylandığında ücret iadeniz yapılır.',
        ],
      },
      { p: 'Puanla ödediğiniz bir siparişi iade ettiğinizde, kullandığınız puanlar hesabınıza geri yüklenir.' },
    ],
  },

  kargo: {
    title: 'Kargo',
    description: 'Raftabul kargo bilgileri: teslimat süreleri ve ücretsiz kargo koşulları.',
    intro: 'Siparişleriniz onaylı satıcılar tarafından özenle hazırlanır ve kargoya verilir.',
    body: [
      { h: 'Teslimat süresi' },
      { p: 'Siparişleriniz genellikle 1–3 iş günü içinde kargoya verilir. Birden fazla satıcıdan ürün aldıysanız, her satıcı kendi siparişini ayrı hazırlar ve ayrı kargolar.' },
      { h: 'Ücretsiz kargo' },
      { p: '200 TL ve üzeri alışverişlerde kargo ücretsizdir.' },
      { h: 'Kargo takibi' },
      { p: '“Siparişlerim” sayfasından kargonuzun durumunu ve takip numarasını görebilirsiniz. Ürün elinize ulaştığında “Teslim aldım” ile teslimatı onaylayabilirsiniz.' },
    ],
  },

  sss: {
    title: 'Sıkça Sorulan Sorular',
    description: 'Raftabul hakkında sıkça sorulan sorular: sipariş, ödeme, kargo, iade ve puan.',
    intro: 'En çok merak edilenleri burada topladık.',
    body: [
      { qa: ['Siparişimi nasıl takip ederim?', 'Hesabınızdaki “Siparişlerim” bölümünden sipariş ve kargo durumunuzu takip edebilirsiniz.'] },
      { qa: ['Ödeme güvenli mi?', 'Evet. Kart bilgileriniz bize iletilmez; ödeme, güvenli ödeme altyapısının kendi ekranında alınır.'] },
      { qa: ['Kargo ne zaman ücretsiz?', '200 TL ve üzeri alışverişlerde kargo ücretsizdir.'] },
      { qa: ['Nasıl iade yaparım?', '“Siparişlerim”den iade talebi oluşturursunuz; satıcı onayladığında iade kodunuzla ürünü kargoya verir, ürün ulaşınca ücretiniz iade edilir.'] },
      { qa: ['Puanları nasıl kazanır ve kullanırım?', 'Üye olduğunuzda, alışveriş yaptığınızda ve yorumunuz yayınlandığında puan kazanırsınız. Ödeme adımında puanlarınızı indirim olarak kullanabilirsiniz.'] },
      { qa: ['Birden fazla satıcıdan aldım, ne olur?', 'Sepetiniz satıcıya göre bölünür; her satıcı kendi siparişini ayrı hazırlar ve ayrı kargolar.'] },
    ],
  },

  'guvenli-alisveris': {
    title: 'Güvenli Alışveriş',
    description: 'Raftabul’da güvenli alışveriş: onaylı satıcılar, güvenli ödeme ve orijinal ürün.',
    intro: 'Alışverişinizin her adımında güvende olmanız için çalışıyoruz.',
    body: [
      { h: 'Onaylı satıcılar' },
      { p: 'Mağazalarımız başvuru ve doğrulama sürecinden geçer; ürünler onaylı satıcılar tarafından listelenir.' },
      { h: 'Güvenli ödeme' },
      { p: 'Kart bilgileriniz Raftabul’a iletilmez. Ödeme, 3-D Secure destekli güvenli ödeme altyapısının kendi ekranında alınır.' },
      { h: 'Orijinal ürün' },
      { p: 'Listelenen tüm ürünler orijinaldir. Bir sorunla karşılaşırsanız iade ve destek süreçlerimiz devrededir.' },
      { h: 'Bilgileriniz güvende' },
      { p: 'Kişisel verileriniz, ilgili mevzuata uygun olarak işlenir ve korunur.' },
    ],
  },

  // ————— Legal pages: structured placeholders, real text to be supplied —————

  gizlilik: {
    title: 'Gizlilik Politikası',
    description: 'Raftabul Gizlilik Politikası.',
    body: [
      { note: 'Bu sayfanın resmi metni henüz eklenmedi. Aşağıdaki başlıklar bir taslak iskelettir; bağlayıcı Gizlilik Politikası metnini bir hukuk danışmanıyla hazırlayıp buraya ekleyin.' },
      { h: 'Toplanan veriler' },
      { p: '[Buraya resmi metin gelecek.]' },
      { h: 'Verilerin kullanımı' },
      { p: '[Buraya resmi metin gelecek.]' },
      { h: 'Çerezler' },
      { p: '[Buraya resmi metin gelecek.]' },
      { h: 'Haklarınız' },
      { p: '[Buraya resmi metin gelecek.]' },
    ],
  },

  kvkk: {
    title: 'KVKK Aydınlatma Metni',
    description: 'Raftabul KVKK Aydınlatma Metni.',
    body: [
      { note: 'Bu sayfanın resmi metni henüz eklenmedi. 6698 sayılı KVKK kapsamındaki aydınlatma metnini bir hukuk danışmanıyla hazırlayıp buraya ekleyin.' },
      { h: 'Veri sorumlusu' },
      { p: '[Buraya resmi metin gelecek.]' },
      { h: 'İşleme amaçları ve hukuki sebepler' },
      { p: '[Buraya resmi metin gelecek.]' },
      { h: 'Veri aktarımı' },
      { p: '[Buraya resmi metin gelecek.]' },
      { h: 'İlgili kişinin hakları' },
      { p: '[Buraya resmi metin gelecek.]' },
    ],
  },

  'kullanim-sartlari': {
    title: 'Kullanım Şartları',
    description: 'Raftabul Kullanım Şartları.',
    body: [
      { note: 'Bu sayfanın resmi metni henüz eklenmedi. Bağlayıcı Kullanım Şartları (Üyelik Sözleşmesi / Mesafeli Satış koşulları dahil) metnini bir hukuk danışmanıyla hazırlayıp buraya ekleyin.' },
      { h: 'Taraflar ve konu' },
      { p: '[Buraya resmi metin gelecek.]' },
      { h: 'Üyelik ve kullanım' },
      { p: '[Buraya resmi metin gelecek.]' },
      { h: 'Sipariş, ödeme ve teslimat' },
      { p: '[Buraya resmi metin gelecek.]' },
      { h: 'Sorumluluğun sınırı' },
      { p: '[Buraya resmi metin gelecek.]' },
    ],
  },
};

export function getContentPage(slug: string): ContentPage | undefined {
  return contentPages[slug];
}

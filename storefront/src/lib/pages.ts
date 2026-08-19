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
      { p: 'Tüm siparişlerde kargo ücretsizdir.' },
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
      { qa: ['Kargo ücreti var mı?', 'Hayır, tüm siparişlerde kargo ücretsizdir.'] },
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

  'on-bilgilendirme': {
    title: 'Ön Bilgilendirme Koşulları',
    description: 'Raftabul Ön Bilgilendirme Koşulları — Mesafeli Sözleşmeler Yönetmeliği kapsamında alıcıya sunulan asgari bilgiler.',
    body: [
     
      { h: '1. Taraflar ve Konu' },
      { p: 'İşbu Ön Bilgilendirme Formu’nun konusu, alıcı (bundan sonra “Alıcı”) ile ürünü satışa sunan satıcı (bundan sonra “Satıcı”) arasında kurulacak Mesafeli Satış Sözleşmesi öncesinde, 6502 sayılı Tüketicinin Korunması Hakkında Kanun ve Mesafeli Sözleşmeler Yönetmeliği uyarınca Alıcı’nın bilgilendirilmesidir.' },
      { p: 'Raftabul, satışın tarafı değildir; Satıcı ile Alıcı arasında sözleşme kurulmasına aracılık eden elektronik ticaret aracı hizmet sağlayıcısıdır. Sözleşme, Alıcı ile ilgili Satıcı arasında kurulur.' },
      { p: 'Alıcı, bu forma ve sözleşmeye ilişkin bilgilere üyeliğine bağlı “Hesabım / Siparişlerim” sayfasından erişebilir; talep etmesi hâlinde bu belgeler e-posta ile de iletilebilir.' },

      { h: '2. Tanımlar' },
      {
        ul: [
          'Alıcı: Bir ürün veya hizmeti ticari ya da mesleki olmayan amaçlarla edinen gerçek kişi.',
          'Satıcı: Ürün/hizmeti Platform üzerinden satışa sunan gerçek veya tüzel kişi (üçüncü taraf satıcı).',
          'Aracı Hizmet Sağlayıcı / Platform işleticisi: Raftabul’u işleten AMIAY ILAÇ KOZMETIK MEDIKAL INSAAT BILISIM SANAYI VE TICARET LIMITED SIRKETI.',
          'Platform: raftabul.com internet sitesi ve varsa mobil uygulaması.',
          'Kanun: 6502 sayılı Tüketicinin Korunması Hakkında Kanun.',
          'Yönetmelik: Mesafeli Sözleşmeler Yönetmeliği.',
        ],
      },

      { h: '3. Satıcı ve Aracı Hizmet Sağlayıcı Bilgileri' },
      { p: 'Aracı Hizmet Sağlayıcı: AMIAY ILAÇ KOZMETIK MEDIKAL INSAAT BILISIM SANAYI VE TICARET LIMITED SIRKETI — Adres: BARAJ MAH. PROF.DR.NECMETTIN ERBAKAN CAD. A NO: 71 C KEPEZ/ ANTALYA — MERSIS: 0069110568500001 — Vergi No/Dairesi: 0691105685 / Antalya Kurumlar — Telefon: 08504553366 — E-posta: destek@raftabul.com' },
      { p: 'Satıcı bilgileri (ticaret unvanı, adres, MERSIS/vergi no, iletişim) her siparişe özel olarak, ilgili siparişin ön bilgilendirme/özet ekranında ve Sipariş Detayı sayfasında gösterilir.' },

      { h: '4. Ürün/Hizmet ve Ödeme Bilgileri' },
      { p: 'Ürünün temel özellikleri (tür, adet, marka/model, fiyat) ürün sayfasında yer alır. Vergiler dâhil satış fiyatı, varsa indirimler ve ödenecek toplam tutar sipariş özetinde açıkça gösterilir.' },
      { p: 'Ödeme, güvenli ödeme altyapısı (PayTR) üzerinden banka/kredi kartı ile alınır. Kart bilgileri Raftabul’a iletilmez ve saklanmaz.' },
      { p: 'v1 kapsamında Raftabul üzerinden yapılan siparişlerde ayrı bir kargo ücreti tahsil edilmez; teslimat koşulları sipariş özetinde belirtilir.' },

      { h: '5. Genel Hükümler' },
      {
        ul: [
          'Ürün, taahhüt edilen süre içinde ve her hâlükârda yasal azami süre olan 30 gün içinde Alıcı’nın bildirdiği adrese kargo ile teslim edilir.',
          'Sepette birden fazla satıcının ürünü varsa, her satıcı kendi siparişini ayrı hazırlar ve ayrı kargolar; teslim süreleri farklılaşabilir.',
          'Alıcı teslim sırasında ürünü muayene etmeli; hasarlı, ayıplı veya eksik ürünü teslim almamalıdır. Teslim alınan ürünün sağlam olduğu kabul edilir.',
          'Herhangi bir nedenle bedelin ödenmemesi/iptali hâlinde Satıcı’nın teslim yükümlülüğü sona erer.',
        ],
      },

      { h: '6. Cayma Hakkı' },
      { p: 'Alıcı, ürünü teslim aldığı günden itibaren 14 gün içinde hiçbir gerekçe göstermeksizin ve cezai şart ödemeksizin sözleşmeden cayma hakkına sahiptir.' },
      { p: 'Cayma talebi “Siparişlerim” üzerinden oluşturulur; Satıcı’nın onayı ve iade koduyla ürün kargoya verilir. Ürün Satıcı’ya ulaşıp uygunluğu onaylandığında, tahsil edilen tutar Alıcı’nın ödeme yöntemine 14 gün içinde iade edilir. Puanla ödenen tutar, kullanılan puanlar olarak geri yüklenir.' },
      { p: 'İade edilecek ürün; kutusu, ambalajı ve varsa aksesuarlarıyla, kullanılmamış ve satılabilir durumda gönderilmelidir.' },

      { h: '7. Cayma Hakkının Kullanılamayacağı Haller' },
      { p: 'Yönetmelik uyarınca, tesliminden sonra ambalaj/bant/mühür gibi koruyucu unsurları açılmış olup iadesi sağlık ve hijyen açısından uygun olmayan ürünler ile Alıcı’nın isteği doğrultusunda hazırlanan, çabuk bozulabilen veya son kullanma tarihi geçebilecek ürünlerde cayma hakkı kullanılamaz. Bu ürünler için Platform üzerinden iade kodu oluşturulamaz.' },

      { h: '8. Kişisel Verilerin Korunması' },
      { p: 'Kişisel verileriniz, 6698 sayılı KVKK ve ilgili mevzuata uygun olarak işlenir. Ayrıntılar için KVKK Aydınlatma Metni ve Gizlilik Politikası sayfalarını inceleyebilirsiniz.' },

      { h: '9. Şikâyet ve Uyuşmazlıkların Çözümü' },
      { p: 'Talep, şikâyet ve önerilerinizi destek@raftabul.com adresi üzerinden iletebilirsiniz. Uyuşmazlıklarda, Ticaret Bakanlığı’nca ilan edilen parasal sınırlar çerçevesinde Alıcı’nın yerleşim yerindeki Tüketici Hakem Heyetleri ile Tüketici Mahkemeleri yetkilidir.' },
    ],
  },

  'mesafeli-satis-sozlesmesi': {
    title: 'Mesafeli Satış Sözleşmesi',
    description: 'Raftabul Mesafeli Satış Sözleşmesi — alıcı ile satıcı arasında elektronik ortamda kurulan sözleşme.',
    body: [
     
      { h: '1. Taraflar' },
      { p: 'İşbu Sözleşme; ürünü satışa sunan Satıcı ile ürünü satın alan Alıcı arasında, aşağıda ve ilgili sipariş özetinde belirtilen bilgiler çerçevesinde elektronik ortamda kurulmuştur. Raftabul’u işleten AMIAY ILAÇ KOZMETIK MEDIKAL INSAAT BILISIM SANAYI VE TICARET LIMITED SIRKETI, sözleşmenin tarafı olmayıp yalnızca aracı hizmet sağlayıcısıdır.' },

      { h: '2. Tanımlar' },
      {
        ul: [
          'Alıcı: Ürünü ticari/mesleki olmayan amaçla satın alan gerçek kişi.',
          'Satıcı: Ürünü Platform üzerinden satışa sunan üçüncü taraf satıcı.',
          'Platform / Aracı Hizmet Sağlayıcı: raftabul.com’u işleten AMIAY ILAÇ KOZMETIK MEDIKAL INSAAT BILISIM SANAYI VE TICARET LIMITED SIRKETI.',
          'Kanun: 6502 sayılı Kanun; Yönetmelik: Mesafeli Sözleşmeler Yönetmeliği.',
        ],
      },

      { h: '3. Sözleşmenin Konusu ve Kapsamı' },
      { p: 'Sözleşmenin konusu, Alıcı’nın Platform üzerinden elektronik ortamda siparişini verdiği ürünün satışı ve teslimi ile tarafların hak ve yükümlülüklerinin, Kanun ve Yönetmelik hükümleri uyarınca belirlenmesidir.' },

      { h: '4. Alıcının Önceden Bilgilendirilmesi' },
      { p: 'Alıcı; ürünün temel nitelikleri, vergiler dâhil toplam fiyatı, ödeme ve teslimat koşulları, cayma hakkı ile bu hakkın kullanım şartları ve şikâyet mercileri hakkında, siparişi tamamlamadan önce Ön Bilgilendirme Koşulları ile bilgilendirildiğini ve bunu teyit ettiğini kabul eder.' },

      { h: '5. Taraf ve Fatura Bilgileri' },
      { p: 'Alıcı bilgileri (ad, teslimat/fatura adresi, iletişim) ile Satıcı bilgileri (unvan, adres, MERSIS/vergi no, iletişim) ilgili siparişin özet ekranında ve Sipariş Detayı sayfasında gösterilir. Fatura, Satıcı tarafından düzenlenir.' },

      { h: '6. Ürün/Hizmet Bilgileri' },
      { p: 'Ürünün türü, adedi, satış fiyatı ve varsa indirimleri ile ödenecek toplam tutar sipariş özetinde yer alır. Ödeme, güvenli ödeme altyapısı (PayTR) üzerinden alınır; kart bilgileri Raftabul’a iletilmez.' },

      { h: '7. Genel Hükümler' },
      {
        ul: [
          'Satıcı, ürünü siparişte belirtilen niteliklere uygun, varsa garanti belgesi ve kullanım kılavuzuyla birlikte teslim etmeyi taahhüt eder.',
          'Ürün, taahhüt edilen sürede ve her hâlükârda 30 günü aşmamak üzere Alıcı’nın adresine teslim edilir; bu süre içinde teslim edilmezse Alıcı sözleşmeyi feshedebilir.',
          'Çok satıcılı sepette her satıcı kendi siparişini ayrı hazırlar ve kargolar.',
          'Teslim alınırken ürün muayene edilmeli; hasarlı/ayıplı ürün teslim alınmamalıdır.',
        ],
      },

      { h: '8. Özel Şartlar' },
      { p: 'Alıcı, aynı sepette birden fazla satıcıdan alışveriş yapabilir; her satıcı için ayrı fatura düzenlenebilir ve teslimatlar yasal süre içinde farklı zamanlarda gerçekleşebilir. Kurumsal fatura talep edilmesi hâlinde, girilen vergi bilgilerinin doğruluğu Alıcı’nın sorumluluğundadır.' },

      { h: '9. Kişisel Verilerin Korunması ve Fikri Haklar' },
      { p: 'Kişisel veriler 6698 sayılı KVKK’ya uygun olarak işlenir (bkz. KVKK Aydınlatma Metni ve Gizlilik Politikası). Platform’a ait her türlü içerik ve hakkın kullanımı AMIAY ILAÇ KOZMETIK MEDIKAL INSAAT BILISIM SANAYI VE TICARET LIMITED SIRKETI’na aittir.' },

      { h: '10. Cayma Hakkı' },
      { p: 'Alıcı, ürünü teslim aldığı günden itibaren 14 gün içinde gerekçe göstermeksizin ve cezai şart ödemeksizin cayma hakkına sahiptir. Cayma talebi “Siparişlerim” üzerinden oluşturulur; Satıcı’nın onayı ve iade koduyla ürün kargoya verilir. Ürün Satıcı’ya ulaşıp uygunluğu onaylandığında tahsil edilen tutar 14 gün içinde iade edilir; puanla ödenen kısım puan olarak geri yüklenir.' },

      { h: '11. Cayma Hakkının Kullanılamayacağı Haller' },
      { p: 'Ambalajı/mührü açılmış olup iadesi sağlık ve hijyen açısından uygun olmayan ürünler, Alıcı’nın isteğine göre hazırlanan ürünler, çabuk bozulabilen veya son kullanma tarihi geçebilecek ürünler ile Yönetmelik’te sayılan diğer hâllerde cayma hakkı kullanılamaz.' },

      { h: '12. Uyuşmazlıkların Çözümü' },
      { p: 'Ticaret Bakanlığı’nca ilan edilen parasal sınırlar çerçevesinde, Alıcı’nın yerleşim yerindeki Tüketici Hakem Heyetleri ile Tüketici Mahkemeleri yetkilidir.' },

      { h: '13. Bildirimler ve Delil Sözleşmesi' },
      { p: 'Taraflar arasındaki bildirimler, sipariş sırasında verilen e-posta ve iletişim bilgileri üzerinden yapılır. Taraflar, Platform kayıtları ile elektronik kayıtların uyuşmazlıklarda geçerli, bağlayıcı ve kesin delil teşkil edeceğini kabul eder.' },

      { h: '14. Yürürlük' },
      { p: 'Alıcı’nın siparişi onaylaması ile bu Sözleşme, sipariş özetinde belirtilen ürün ve tutarlar üzerinden yürürlüğe girer.' },
    ],
  },
};

export function getContentPage(slug: string): ContentPage | undefined {
  return contentPages[slug];
}

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
	  {
        ul: [
          'BARAJ MAH. PROF.DR.NECMETTIN ERBAKAN CAD. A NO: 71 C KEPEZ/ ANTALYA adresinde mukim AMIAY ILAÇ KOZMETIK MEDIKAL INSAAT BILISIM SANAYI VE TICARET LIMITED SIRKETI ("Amiay "), kullanıcıların raftabul.com ("Websitesi") üzerinden ilettikleri kişisel bilgilerini, Gizlilik Politikası ile belirlenen amaçlar ve kapsam dışında kullanmayacak, ayrıca izinsiz olarak üçüncü kişilerle paylaşmayacaktır. Bununla beraber kullanıcı, paylaşmış olduğu bilgilerinin kendisine özel avantajların sunulabilmesi, satış, pazarlama ve benzer amaçlı her türlü iletişim faaliyetlerinin bildirimi maksatlarıyla, tüm Amiay iştirakleri ile de paylaşımına izin vermektedir.',
          'Kişisel bilgiler; ad soyad, doğum tarihi, ev adresi, mobil ve sabit telefon numarası, e-posta adresi gibi kullanıcıyı doğrudan ya da dolaylı olarak tanımlamaya yönelik her türlü kişisel bilgiyi içermekte olup, kısaca “Gizli Bilgiler” olarak anılacaktır.',
          'Amiay, kişisel bilgileri kendi bünyesinde profilleme, istatistiksel çalışmalar, reklam, tanıtım, pazarlama ve sair iletişim faaliyetleri amacıyla kullanabilecek ve sadece bu çalışmaların yapılması amacıyla bilginiz dahilinde olan 3.kişiler ile paylaşabilecektir.',
          'Amiay, kişisel bilgileri kesinlikle gizli tutmayı, bunu bir sır saklama yükümlülüğü olarak addetmeyi, gizliliğin sağlanması ve sürdürülmesi, gizli bilginin tamamının veya herhangi bir kısmının kamu alanına girmesini veya yetkisiz kullanımını veya üçüncü bir kişiye ifşasını önlemek için gerekli tedbirleri almayı ve gerekli özeni göstermeyi taahhüt etmektedir. Amiay’ın gerekli bilgi güvenliği önlemlerini almasına karşın websitesine ve sisteme yapılan saldırılar sonucunda gizli bilgilerin zarar görmesi veya üçüncü kişilerin eline geçmesi durumunda, Amiay’ın herhangi bir sorumluluğu olmayacaktır.',
          'Amiay, kullanıcılara ve kullanıcıların Web sitesinin kullanımına dair bilgileri, teknik bir iletişim dosyasını (Kurabiye-Cookie) kullanarak elde edebilir. Ancak, kullanıcılar dilerlerse teknik iletişim dosyasının gelmemesi veya teknik iletişim dosyası gönderildiğinde ikaz verilmesini sağlayacak biçimde tarayıcı ayarlarını değiştirebilirler.',
          'Kullanıcılar, Üyelik/Kişisel bilgilerini ve iletişim tercihlerini her zaman sisteme giriş yaparak güncelleyebilirler. Bu konuda taleplerinizi ayrıca Websitemizde yer alan iletişim bilgilerinden bize ulaşarak da iletebilirsiniz. Talebiniz en kısa sürede değerlendirilerek uygulamaya alınacaktır.',
          'raftabul.com dan promosyon ve duyuru mesajı içeren e-posta almak istemiyorsanız size ulaşan kampanya mailinin alt kısmında "mail listesinden ayrıl" bağlantısına tıklarak listeden ayrılabilirsiniz.',
          'Her Kullanıcı, işbu Websitesini ziyaret ederek, işbu Gizlilik Politikası hükümlerini kabul etmiş sayılacaktır.',
        ],
      },
      { h: 'Toplanan veriler' },
      { p: 'Aşağıda raftabul.com tarafından işlenen ve Kanun uyarınca kişisel veri sayılan verilerin hangileri olduğu sıralanmıştır. Aksi açıkça belirtilmedikçe, işbu Politika kapsamında arz edilen hüküm ve koşullar kapsamında “kişisel veri” ifadesi aşağıda yer alan bilgileri kapsayacaktır.' },
      {
        ul: [
			'Kimlik Bilgisi',
			'İletişim Bilgisi',
			'Kullanıcı Bilgisi',
			'Kullanıcı İşlem Bilgisi',
			'İşlem Güvenliği Bilgisi',
			'Finansal Bilgi',
			'Pazarlama Bilgisi',
			'Talep/Şikayet Yönetimi Bilgisi',
		],
	  },
	  { p: 'Kişisel Verilerin Korunması Kanunu’nun 3. ve 7. maddeleri dairesince, geri döndürülemeyecek şekilde anonim hale getirilen veriler, anılan kanun hükümleri uyarınca kişisel veri olarak kabul edilmeyecek ve bu verilere ilişkin işleme faaliyetleri işbu Politika hükümleri ile bağlı olmaksızın gerçekleştirecektir.' },
	  { h: 'Verilerin kullanımı' },
      { p: 'Amiay, Veri Sahibi tarafından sağlanan kişisel verileri, üyelik kaydı ve hesabının oluşturulması ve buna ilişkin kayıtların tutulması, Veri Sahibi’nin Websitesi üzerinden sağlanan hizmetlerden faydalandırılması sistem hatalarının tespit edilerek performans takibinin yapılması ve Platform’un işleyişinin iyileştirilmesi, bakım ve destek hizmetleri ile yedekleme hizmetlerinin sunulması amaçları dahil olmak üzere Amiay tarafından sunulan ürün ve hizmetlerden ilgili kişileri faydalandırmak için gerekli çalışmaların iş birimleri tarafından yapılması ve ilgili iş süreçlerinin yürütülmesi ile bu ürün ve hizmetlerin ilgili kişilerin beğeni, kullanım alışkanlıkları ve ihtiyaçlarına göre özelleştirilerek ilgili kişilere önerilmesi ve tanıtılması için gerekli olan aktivitelerin planlanması ve icrası, Amiay tarafından yürütülen ticari faaliyetlerin gerçekleştirilmesi için ilgili iş birimleri tarafından gerekli çalışmaların yapılması ve buna bağlı iş süreçlerinin yürütülmesi, Amiay ve iş ilişkisi içerisinde bulunduğu kişilerin hukuki, teknik ve ticari-iş güvenliğinin temini ile Amiay’nın ticari ve/veya iş stratejilerinin planlanması ve icrası amaçlarıyla işlenebilecektir.' },
      { h: 'Çerezler' },
      { p: 'AMIAY ILAÇ KOZMETIK MEDIKAL INSAAT BILISIM SANAYI VE TICARET LIMITED SIRKETI. (“Amiay”) olarak, kullanıcılarımızın hizmetlerimizden güvenli ve eksiksiz şekilde faydalanmalarını sağlamak amacıyla sitemizi kullanan kişilerin gizliliğini korumak için çalışıyoruz. Çoğu web sitesinde olduğu gibi, raftabul.com (“Site”) ziyaretçilere kişisel içerik ve reklamlar göstermek, site içinde analitik faaliyetler gerçekleştirmek ve ziyaretçi kullanım alışkanlıklarını takip etmek amacıyla Çerezler kullanılmaktadır. İşbu Çerez Politakası raftabul.com Gizlilik Politikası’nın ayrılmaz bir parçasıdır. Amiay, bu Çerez Politikası’nı (“Politika”) Site’de hangi Çerezlerin kullanıldığını ve kullanıcıların bu konudaki tercihlerini nasıl yönetebileceğini açıklamak amacıyla hazırlamıştır. ' },
      { h: 'Hangi Çerezler Kullanılmaktadır?' },
      { p: 'Çerezler, sahipleri, kullanım ömürleri ve kullanım amaçları açısında kategorize edilebilir:' },
	  {
        ul: [
			'Çerezi yerleştiren tarafa göre, Platform çerezleri ve üçüncü taraf Çerezler kullanılmaktadır. Platform çerezleri, raftabul tarafından oluşturulurken, üçüncü taraf çerezlerini raftabul ile iş birlikteliği olan farklı firmalar yönetmektedir.',
			'Aktif olduğu süreye göre, oturum çerezleri ve kalıcı çerezler kullanılmaktadır. Oturum çerezleri ziyaretçinin Platform’u terk etmesiyle birlikte silinirken, kalıcı çerezler ise kullanım alanına bağlı olarak çeşitli sürelerle ziyaretçilerin cihazlarında kalabilmektedir.',
			'Kullanım amaçlarına göre, Platform’da teknik çerezler, doğrulama çerezleri, hedefleme/reklam çerezleri, kişiselleştirme çerezleri ve analitik çerezler kullanılmaktadır.',
		],
	  },
	  { h: 'Haklarınız' },
      { p: '6698 Sayılı Kişisel Verilerin Korunması Kanunu’nun 11. maddesi uyarınca ziyaretçiler, raftabul’a başvurarak, kendileriyle ilgili,' },
	  {
        ul: [
			'Kişisel veri işlenip işlenmediğini öğrenme,',
			'Kişisel verileri işlenmişse buna ilişkin bilgi talep etme,',
			'Kişisel verilerin işlenme amacını ve bunların amacına uygun kullanılıp kullanılmadığını öğrenme,',
			'Yurt içinde veya yurt dışında kişisel verilerin aktarıldığı üçüncü kişileri bilme,',
			'Kişisel verilerin eksik veya yanlış işlenmiş olması hâlinde bunların düzeltilmesini isteme ve bu kapsamda yapılan işlemin kişisel verilerin aktarıldığı üçüncü kişilere bildirilmesini isteme,',
			'Kanun ve ilgili diğer kanun hükümlerine uygun olarak işlenmiş olmasına rağmen, işlenmesini gerektiren sebeplerin ortadan kalkması hâlinde kişisel verilerin silinmesini veya yok edilmesini isteme ve bu kapsamda yapılan işlemin kişisel verilerin aktarıldığı üçüncü kişilere bildirilmesini isteme,',
			'İşlenen verilerin münhasıran otomatik sistemler vasıtasıyla analiz edilmesi suretiyle kişinin kendisi aleyhine bir sonucun ortaya çıkmasına itiraz etme,',
			'Kişisel verilerin kanuna aykırı olarak işlenmesi sebebiyle zarara uğraması hâlinde zararın giderilmesini talep etme haklarına sahiptir',
		],
	  },
	  { p: 'Söz konusu haklar, kişisel veri sahipleri tarafından 6698 sayılı Kanun Kapsamında Amiay tarafından hazırlanan Kişisel Verilerin İşlenmesi ve Korunmasına ilişkin Politika’da belirtilen yöntemlerle iletildiğinde her hâlükârda 30 (otuz) gün içerisinde değerlendirilerek sonuçlandırılacaktır. Taleplere ilişkin olarak herhangi bir ücret talep edilmemesi esas olmakla birlikte, Amiay, Kişisel Verileri Koruma Kurulu tarafından belirlenen ücret tarifesi üzerinden ücret talep etme hakkını saklı tutar.' },
    ],
  },

  kvkk: {
    title: 'KVKK Aydınlatma Metni',
    description: 'Raftabul KVKK Aydınlatma Metni.',
    body: [
      { note: 'Bu sayfanın resmi metni henüz eklenmedi. 6698 sayılı KVKK kapsamındaki aydınlatma metnini bir hukuk danışmanıyla hazırlayıp buraya ekleyin.' },
      { h: 'Veri sorumlusu' },
      { p: '6698 sayılı Kişisel Verilerin Korunması Kanunu ("KVKK") uyarınca, kişisel verileriniz veri sorumlusu sıfatıyla AMIAY ILAÇ KOZMETIK MEDIKAL INSAAT BILISIM SANAYI VE TICARET LIMITED SIRKETI tarafından işlenmektedir.' },
      { p: 'Veri sorumlusunun iletişim bilgileri: Adres: BARAJ MAH. PROF.DR.NECMETTIN ERBAKAN CAD. A NO: 71 C KEPEZ/ ANTALYA, E-posta:destek@raftabul.com' },
	  {h: '2. İşlenen Kişisel Veriler',},
		{
		p: 'Raftabul internet sitesi ve ilgili hizmetler üzerinden gerçekleştirilen işlemler kapsamında; kimlik bilgileri, iletişim bilgileri, adres bilgileri, müşteri işlem bilgileri, sipariş ve alışveriş bilgileri, fatura bilgileri, işlem güvenliği bilgileri, internet sitesi kullanım bilgileri ve mevzuatın izin verdiği ölçüde işlem güvenliğine ilişkin teknik veriler işlenebilmektedir.'
		},
		{
		p: 'Ödeme işlemlerinde kullanılan banka veya ödeme kuruluşlarına ait kart bilgileriniz, ödeme hizmetinin niteliğine göre doğrudan ilgili ödeme hizmeti sağlayıcısı tarafından işlenebilir. Raftabul tarafından gerekli olmadığı sürece kart bilgilerinizin tamamı saklanmaz.'
		},

		{
		h: '3. Kişisel Verilerin İşlenme Amaçları',
		},
		{
		p: 'Kişisel verileriniz; üyelik ve hesap işlemlerinin yürütülmesi, siparişlerin alınması ve sonuçlandırılması, ürün ve hizmetlerin sunulması, ödeme ve faturalandırma işlemlerinin gerçekleştirilmesi, ürünlerin teslim edilmesi, müşteri hizmetlerinin yürütülmesi, talep ve şikâyetlerin değerlendirilmesi, satış sonrası destek hizmetlerinin sağlanması, iletişim faaliyetlerinin yürütülmesi, bilgi güvenliğinin sağlanması, internet sitesinin güvenliğinin ve işleyişinin sağlanması, dolandırıcılık ve kötüye kullanımın önlenmesi, muhasebe ve finans işlemlerinin yürütülmesi ve yasal yükümlülüklerin yerine getirilmesi amaçlarıyla işlenebilir.'
		},
		{
		p: 'Ayrıca, gerekli hukuki şartların bulunması ve gerektiğinde açık rızanızın alınması koşuluyla; kampanya, indirim, tanıtım ve pazarlama faaliyetlerinin yürütülmesi, size özel tekliflerin oluşturulması ve elektronik ileti gönderilmesi amacıyla da kişisel verileriniz işlenebilir.'
		},

		{
		h: '4. Kişisel Verilerin Toplanma Yöntemi ve Hukuki Sebebi',
		},
		{
		p: 'Kişisel verileriniz; Raftabul internet sitesi, üyelik ve sipariş formları, iletişim kanalları, müşteri hizmetleri, elektronik iletişim araçları, ödeme işlemleri, teslimat süreçleri, çerezler ve benzeri çevrimiçi teknolojiler aracılığıyla otomatik veya kısmen otomatik yöntemlerle toplanabilir.'
		},
		{
		p: 'Kişisel verileriniz; KVKK’nın 5. maddesinde belirtilen kanunlarda açıkça öngörülmesi, sözleşmenin kurulması veya ifası için gerekli olması, veri sorumlusunun hukuki yükümlülüğünü yerine getirmesi, bir hakkın tesisi, kullanılması veya korunması için veri işlemenin zorunlu olması, ilgili kişinin temel hak ve özgürlüklerine zarar vermemek kaydıyla veri sorumlusunun meşru menfaatleri için zorunlu olması ve gerekli hallerde açık rızanın bulunması hukuki sebeplerine dayanılarak işlenmektedir.'
		},

		{
		h: '5. Kişisel Verilerin Aktarılması',
		},
		{
		p: 'Kişisel verileriniz; sipariş ve teslimat süreçlerinin yürütülmesi amacıyla kargo ve lojistik hizmet sağlayıcılarına, ödeme işlemlerinin gerçekleştirilmesi amacıyla ödeme kuruluşlarına ve bankalara, faturalandırma ve muhasebe süreçlerinin yürütülmesi amacıyla yetkili hizmet sağlayıcılara, teknik altyapı ve bilişim hizmetlerinin sağlanması amacıyla hizmet sağlayıcılara ve mevzuatın izin verdiği ölçüde yetkili kamu kurum ve kuruluşlarına aktarılabilir.'
		},
		{
		p: 'Kişisel verileriniz, hizmetlerin sunulması için gerekli olması halinde Raftabul adına hizmet veren teknoloji, barındırma, e-posta, analiz, güvenlik ve benzeri hizmet sağlayıcılarına da aktarılabilir. Yurt dışına veri aktarımı söz konusu olması halinde KVKK’da öngörülen şartlara uygun hareket edilir.'
		},

		{
		h: '6. Kişisel Verilerin Saklanma Süresi',
		},
		{
		p: 'Kişisel verileriniz, ilgili mevzuatta öngörülen saklama süreleri boyunca veya işleme amacının gerektirdiği süre kadar saklanır. Saklama süresinin sona ermesi veya kişisel verilerin işlenmesini gerektiren sebeplerin ortadan kalkması halinde kişisel veriler, ilgili mevzuata uygun şekilde silinir, yok edilir veya anonim hale getirilir.'
		},

		{
		h: '7. Çerezler ve Benzeri Teknolojiler',
		},
		{
		p: 'Raftabul internet sitesinde kullanıcı deneyiminin geliştirilmesi, sitenin güvenli ve düzgün şekilde çalışmasının sağlanması, tercihlerin hatırlanması ve gerekli durumlarda site kullanımının analiz edilmesi amacıyla çerezler ve benzeri teknolojiler kullanılabilir.'
		},
		{
		p: 'Zorunlu olmayan çerezlerin kullanımı ve kişisel verilerin bu teknolojiler aracılığıyla işlenmesi bakımından ilgili mevzuatta öngörülen şartlara uygun hareket edilir. Çerez tercihlerinizi internet tarayıcınızın veya sitemizde sunulan çerez yönetim araçlarının izin verdiği ölçüde değiştirebilirsiniz.'
		},

		{
		h: '8. İlgili Kişinin KVKK Kapsamındaki Hakları',
		},
		{
		p: 'KVKK’nın 11. maddesi kapsamında ilgili kişi olarak; kişisel verilerinizin işlenip işlenmediğini öğrenme, işlenmişse buna ilişkin bilgi talep etme, işlenme amacını ve bunların amacına uygun kullanılıp kullanılmadığını öğrenme, yurt içinde veya yurt dışında kişisel verilerin aktarıldığı üçüncü kişileri bilme, kişisel verilerin eksik veya yanlış işlenmiş olması hâlinde bunların düzeltilmesini isteme ve KVKK’da öngörülen şartlar çerçevesinde kişisel verilerin silinmesini veya yok edilmesini isteme haklarına sahipsiniz.'
		},
		{
		p: 'Bunun yanında; düzeltme, silme veya yok etme işlemlerinin kişisel verilerin aktarıldığı üçüncü kişilere bildirilmesini isteme, münhasıran otomatik sistemler vasıtasıyla analiz edilmesi sonucunda aleyhinize bir sonucun ortaya çıkmasına itiraz etme ve kanuna aykırı veri işlenmesi nedeniyle zarara uğramanız hâlinde zararın giderilmesini talep etme haklarına da sahipsiniz.'
		},

		{
		h: '9. Başvuru Yöntemi',
		},
		{
		p: 'KVKK kapsamındaki haklarınızı kullanmak için talebinizi destek@raftabul.com adresine e-posta yoluyla veya BARAJ MAH. PROF.DR.NECMETTIN ERBAKAN CAD. A NO: 71 C KEPEZ/ ANTALYA adresine yazılı olarak iletebilirsiniz.'
		},
		{
		p: 'Başvurularınız, KVKK ve ilgili ikincil mevzuatta öngörülen usul ve esaslar çerçevesinde değerlendirilerek sonuçlandırılır. Başvurunun niteliğine göre kimlik doğrulaması yapılması veya ek bilgi ve belge talep edilmesi mümkündür.'
		},

		{
		h: '10. Yürürlük',
		},
		{
		p: 'Bu Aydınlatma Metni, kişisel verilerin işlenmesine ilişkin uygulamalarımızdaki değişiklikler veya mevzuat gereklilikleri doğrultusunda güncellenebilir. Güncel metin Raftabul internet sitesinde yayımlandığı tarih itibarıyla geçerlidir.'
		}
    ],
  },

  'kullanim-sartlari': {
    title: 'Kullanım Şartları',
    description: 'Raftabul Kullanım Şartları.',
    body: [
		{ h: '1. Taraflar ve Konu' },
		{ p: 'Bu Kullanım Şartları, Raftabul internet sitesi ve bu site üzerinden sunulan hizmetlerin kullanımına ilişkin koşulları düzenlemektedir. Raftabul internet sitesini ziyaret eden, üye olan veya site üzerinden alışveriş yapan tüm kullanıcılar bu Kullanım Şartları’nı kabul etmiş sayılır.' },
		{ p: 'AMIAY ILAÇ KOZMETIK MEDIKAL INSAAT BILISIM SANAYI VE TICARET LIMITED SIRKETI ("Raftabul", "Şirket", "biz") tarafından işletilen Raftabul internet sitesi üzerinden sunulan ürün ve hizmetlerden yararlanabilmek için kullanıcıların işbu Kullanım Şartları’na, yürürlükteki mevzuata ve internet sitesinde yayımlanan diğer politika ve sözleşmelere uygun hareket etmesi gerekmektedir.' },

		{ h: '2. Üyelik ve Kullanım' },
		{ p: 'Raftabul üzerinden alışveriş yapmak için üyelik oluşturulması gerekli olan işlemlerde kullanıcıların doğru, güncel ve eksiksiz bilgi vermesi gerekir. Kullanıcı, hesabına ilişkin bilgilerin doğruluğundan ve hesap bilgilerinin güvenliğinden sorumludur.' },
		{ p: 'Üyelik hesabının kullanıcı adına gerçekleştirdiği işlemlerden, aksi kanıtlanmadıkça hesap sahibi sorumludur. Kullanıcı, hesap bilgilerinin üçüncü kişiler tarafından ele geçirildiğini veya yetkisiz kullanıldığını fark etmesi halinde durumu Raftabul’a derhal bildirmelidir.' },
		{ p: 'Kullanıcı; internet sitesini hukuka aykırı amaçlarla, üçüncü kişilerin haklarını ihlal edecek şekilde, sistemlerin güvenliğini tehlikeye düşürecek biçimde veya sitenin normal çalışmasını engelleyecek herhangi bir yöntemle kullanamaz.' },
		{ p: 'Raftabul, gerekli gördüğü hallerde; mevzuata aykırı kullanım, kötüye kullanım, güvenlik riski veya işbu Kullanım Şartları’na aykırılık tespit edilmesi durumunda ilgili hesabı geçici olarak sınırlandırma, askıya alma veya kapatma hakkını saklı tutar.' },

		{ h: '3. Ürün Bilgileri ve Fiyatlar' },
		{ p: 'Raftabul internet sitesinde yer alan ürün açıklamaları, görseller, stok bilgileri, fiyatlar, kampanyalar ve diğer bilgiler mümkün olduğunca güncel ve doğru tutulmaya çalışılır. Ancak teknik nedenlerle veya veri güncellemelerinden kaynaklanan hatalar meydana gelebilir.' },
		{ p: 'Ürün fiyatları ve kampanya koşulları, aksi belirtilmedikçe KDV dahil olarak gösterilir. Raftabul, ürün fiyatlarında ve kampanya koşullarında önceden bildirimde bulunmaksızın değişiklik yapma hakkını saklı tutar. Siparişin tamamlanmasıyla oluşan sözleşmesel hak ve yükümlülükler bakımından yürürlükteki mevzuat hükümleri uygulanır.' },
		{ p: 'Stokta bulunmayan veya tedarik edilemeyen ürünler bakımından Raftabul, ilgili siparişi mevzuata uygun şekilde iptal etme ve ödenmiş tutarı kullanıcıya iade etme hakkına sahiptir.' },

		{ h: '4. Sipariş, Ödeme ve Teslimat' },
		{ p: 'Kullanıcı tarafından verilen sipariş, sipariş bilgilerinin ve ödeme işleminin sistem tarafından alınmasıyla oluşturulur. Siparişin kabulü, hazırlanması, gönderilmesi ve teslimine ilişkin süreçler yürürlükteki tüketici mevzuatına uygun olarak gerçekleştirilir.' },
		{ p: 'Ödeme işlemleri, Raftabul tarafından sunulan ödeme yöntemleri üzerinden veya yetkili ödeme hizmeti sağlayıcıları aracılığıyla gerçekleştirilebilir. Ödeme işlemlerinin güvenliği ve teknik olarak gerçekleştirilmesi amacıyla ilgili ödeme kuruluşlarının kendi güvenlik ve kullanım koşulları da uygulanabilir.' },
		{ p: 'Ürünlerin teslimatı, sipariş sırasında kullanıcı tarafından belirtilen teslimat adresine, seçilen veya Raftabul tarafından belirlenen kargo ya da lojistik hizmet sağlayıcısı aracılığıyla gerçekleştirilir.' },
		{ p: 'Teslimat süreleri ürünün niteliği, stok durumu, ödeme onayı, kargo süreçleri ve mücbir sebepler gibi durumlara bağlı olarak değişebilir. Tüketicinin mevzuattan doğan teslimat, cayma, iade ve diğer hakları saklıdır.' },

		{ h: '5. İptal, İade ve Cayma Hakkı' },
		{ p: 'Tüketicilerin sipariş, teslimat, cayma hakkı, iade, değişim ve bedel iadesine ilişkin hakları 6502 sayılı Tüketicinin Korunması Hakkında Kanun ve ilgili ikincil mevzuat kapsamında belirlenir.' },
		{ p: 'Cayma hakkının kullanılamadığı ürün ve hizmetler bakımından ilgili mevzuatta yer alan istisnalar uygulanır. İade ve cayma işlemlerine ilişkin ayrıntılı koşullar Raftabul internet sitesinde yayımlanan ilgili politika ve sözleşmelerde belirtilir.' },

		{ h: '6. Fikri Mülkiyet Hakları' },
		{ p: 'Raftabul internet sitesinde yer alan tasarım, logo, marka, metin, grafik, görsel, yazılım, kod, veri tabanı, içerik ve diğer unsurlar üzerindeki fikri ve sınai mülkiyet hakları, ilgili hak sahiplerine aittir.' },
		{ p: 'Site içeriği; Raftabul’un veya ilgili hak sahibinin yazılı izni olmaksızın kopyalanamaz, çoğaltılamaz, dağıtılamaz, yayımlanamaz, ticari amaçlarla kullanılamaz veya başka bir internet sitesinde ya da dijital ortamda yeniden yayınlanamaz.' },

		{ h: '7. Kullanıcı İçerikleri' },
		{ p: 'Kullanıcıların ürün değerlendirmeleri, yorumlar veya diğer alanlarda paylaştığı içeriklerin hukuka uygun, doğru ve üçüncü kişilerin haklarını ihlal etmeyecek nitelikte olması gerekir.' },
		{ p: 'Kullanıcılar; hakaret, tehdit, ayrımcı ifade, kişisel veri, telif hakkıyla korunan ve paylaşma yetkisi bulunmayan içerik, reklam, spam veya hukuka aykırı herhangi bir içerik paylaşamaz.' },
		{ p: 'Raftabul, mevzuata veya site kurallarına aykırı olduğunu değerlendirdiği kullanıcı içeriklerini kaldırma veya erişime kapatma hakkını saklı tutar.' },

		{ h: '8. Sorumluluğun Sınırı' },
		{ p: 'Raftabul, internet sitesinin kesintisiz, hatasız veya her zaman erişilebilir olacağını garanti etmez. Bakım, güncelleme, teknik arıza, internet altyapısındaki sorunlar, üçüncü taraf hizmet sağlayıcılarından kaynaklanan kesintiler ve mücbir sebepler nedeniyle hizmetlerde geçici kesintiler meydana gelebilir.' },
		{ p: 'Raftabul, yürürlükteki emredici mevzuat hükümleri saklı kalmak kaydıyla; kullanıcı tarafından sağlanan yanlış veya eksik bilgilerden, kullanıcının hesap güvenliğini korumamasından, üçüncü taraf sistemlerden veya kullanıcının internet bağlantısı ve cihazlarından kaynaklanan sorunlardan sorumlu tutulamaz.' },
		{ p: 'Raftabul’un kanunen sorumlu olduğu hallerde tüketicilerin ve kullanıcıların mevzuattan doğan hakları saklıdır.' },

		{ h: '9. Üçüncü Taraf Hizmetleri ve Bağlantılar' },
		{ p: 'Raftabul internet sitesinde ödeme kuruluşları, kargo firmaları, teknoloji sağlayıcıları veya diğer üçüncü taraf hizmet sağlayıcılarına ait sistemlere ve internet sitelerine yönlendiren bağlantılar bulunabilir. Bu hizmetlerin kullanımında ilgili üçüncü tarafların kendi koşulları ve politikaları geçerli olabilir.' },
		{ p: 'Raftabul, üçüncü taraf internet sitelerinin içeriklerinden, güvenlik uygulamalarından veya hizmetlerin kesintisiz çalışmasından sorumlu değildir.' },

		{ h: '10. Kişisel Verilerin Korunması' },
		{ p: 'Raftabul tarafından gerçekleştirilen kişisel veri işleme faaliyetleri, 6698 sayılı Kişisel Verilerin Korunması Kanunu ve ilgili mevzuata uygun olarak yürütülür. Kişisel verilerin işlenmesine ilişkin ayrıntılı bilgiler Raftabul KVKK Aydınlatma Metni ve ilgili gizlilik politikalarında açıklanmaktadır.' },

		{ h: '11. Değişiklikler' },
		{ p: 'Raftabul, yürürlükteki mevzuat, teknik altyapı, hizmet kapsamı veya ticari faaliyetlerde meydana gelen değişiklikler doğrultusunda bu Kullanım Şartları’nı güncelleme hakkını saklı tutar.' },
		{ p: 'Güncellenen Kullanım Şartları internet sitesinde yayımlandığı tarihten itibaren geçerli olur. Kullanıcıların siteyi kullanmaya devam etmesi, güncel şartları kabul ettiği anlamına gelir.' },

		{ h: '12. Uygulanacak Hukuk ve Uyuşmazlıkların Çözümü' },
		{ p: 'Bu Kullanım Şartları’nın uygulanmasında Türkiye Cumhuriyeti mevzuatı geçerlidir.' },
		{ p: 'Tüketici işlemlerinden doğan uyuşmazlıklarda, yürürlükteki mevzuat uyarınca Tüketici Hakem Heyetleri ve Tüketici Mahkemelerinin görev ve yetkilerine ilişkin hükümler uygulanır.' },

		{ h: '13. İletişim' },
		{ p: 'Kullanım Şartları hakkında sorularınız, talepleriniz veya bildirimleriniz için destek@raftabul.com adresinden veya BARAJ MAH. PROF.DR.NECMETTIN ERBAKAN CAD. A NO: 71 C KEPEZ/ ANTALYA üzerinden Raftabul ile iletişime geçebilirsiniz.' }
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

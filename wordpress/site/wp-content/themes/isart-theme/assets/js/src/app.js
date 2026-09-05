var $ = jQuery;
$(document).ready(function () {

  var headerHeight = $('.main-header').height();
  $('.pt-prime').css('padding-top', headerHeight + 10);

  jQuery(function ($) {
    $.mask.definitions['N'] = '[/0-6|9/]';
    $(".maskedPhone").mask("+7 (N99) 999-99-99", {
      placeholder: "+7 (___) ___-__-__"
    });
  });

  $('.btn-hamburger').click(function () {
    if ($('.btn-hamburger').hasClass('active')) {
      $('html').removeClass('noscroll');
      $('.mobile_menu').removeClass('active');
      $('#overlay').removeClass('active');
      $('.btn-hamburger').removeClass('active');
    } else {
      $('html').addClass('noscroll');
      $('.btn-hamburger').addClass('active');
      $('.mobile_menu').addClass('active');
      $('#overlay').addClass('active');
    }

  });

  $('.btn_search').click(function () {
    if ($('.btn_search').hasClass('active')) {
      $('.search-widget-wrapper').removeClass('visible');
      $('#overlay').removeClass('active');
      $('.btn_search').removeClass('active');
    } else {
      $('.btn_search').addClass('active');
      $('.search-widget-wrapper').addClass('visible')
      $('#overlay').addClass('active');
    }
    
  })


  $('#overlay').click(function () {
    $('html').removeClass('noscroll');
    $('.mobile_menu').removeClass('active');
    $('#overlay').removeClass('active');
    $('.btn-hamburger').removeClass('active');
    $('.btn_search').removeClass('active');
    $('.search-widget-wrapper').removeClass('visible');
  });



  $('.faq-item-header').click(function () {
    // $('.faq-item-header').not(this).parent().removeClass('active');
    // $('.faq-item-header').not(this).parent().find('.faq-item-content').slideUp();
    $(this).parent().toggleClass('active');
    $(this).parent().find('.faq-item-content').slideToggle();
  })



  $('.btn_tab').click(function () {
    var newElem = $(this);
    var prevElem = $('.btn_tab.active');
    var id = newElem.attr("data-id");
    prevElem.removeClass('active');
    newElem.addClass('active');

    if (id) {
      $(".mobile_menu_list_inner ul:visible").removeClass('vis');
      $('.mobile_menu_list_inner ul[dataTab-id="' + id + '"]').addClass('vis');
    }
  });


  $('.btn_auth_tab').click(function () {
    var newElem = $(this);
    console.log(newElem);
    var prevElem = $('.btn_auth_tab.active');
    var id = newElem.attr("data-id");
    prevElem.removeClass('active');
    newElem.addClass('active');

    if (id) {
      $(".auth-item:visible").removeClass('vis');
      $('.auth-item[dataTab-id="' + id + '"]').addClass('vis');
    }
  });

  function qty_min() {
    if (document.getElementById("qty").value && document.getElementById("qty").value < 1) {
      this.value = 1
    }
  }
  function qty_max() {
    if (document.getElementById("qty").value && document.getElementById("qty").value > 999) {
      this.value = 999
    }
  }

  if (document.getElementById("qty")) {
    document.getElementById("qty").addEventListener('input', qty_min);
    document.getElementById("qty").addEventListener('input', qty_max);
  }


  let qty
  $(document).on('keyup change', '.qty_input', function () {

    if ($(this).val() && $(this).val() > 0) {
      qty = $(this).val();
    } else {
      qty = 1;
    }

    $(this).parents('.js-product_cart').find('.btn_buy').attr('data-quantity', qty);

  });

  $('.qty').each(function () {
    var spinner = $(this),
      input = spinner.find('input[type="number"]'),
      btnUp = spinner.find('.qty_plus'),
      btnDown = spinner.find('.qty_minus'),
      min = input.attr('min'),
      max = input.attr('max') || 999,
      oldValue = 0;
    btnUp.click(function () {
      var oldValue = parseFloat(input.val());
      if (!oldValue) {
        var newVal = 1;
      }
      else if (oldValue >= max) {
        var newVal = oldValue;
      } else {
        var newVal = oldValue + 1;
      }
      spinner.find("input").val(newVal);
      spinner.find("input").trigger("change");
      spinner.parents('.js-product_cart').find('.btn_buy').attr('data-quantity', newVal)
    });

    btnDown.click(function () {
      var oldValue = parseFloat(input.val());
      if (!oldValue) {
        var newVal = 1;
      }
      else if (oldValue <= min) {
        var newVal = oldValue;
      } else {
        var newVal = oldValue - 1;
      }
      spinner.find("input").val(newVal);
      spinner.find("input").trigger("change");
      spinner.parent('.js-product_cart').find('.btn_buy').attr('data-quantity', newVal)

    });

  });


  $(document).on('click', 'button.plus, button.minus', function () {

    var qty = $(this).parent().find('input'),
      val = parseInt(qty.val()),
      min = parseInt(qty.attr('min')),
      max = parseInt(qty.attr('max')),
      step = parseInt(qty.attr('step'));

    // дальше меняем значение количества в зависимости от нажатия кнопки
    if ($(this).is('.plus')) {
      if (max && (max <= val)) {
        qty.val(max).change();
      } else {
        qty.val(val + step).change();
      }
    } else {
      if (min && (min >= val)) {
        qty.val(min).change();
      } else if (val > 1) {
        qty.val(val - step).change();
      }
    }

  });



});

var swiperPartners = new Swiper(".catsSwiper", {
  spaceBetween: 15,
  slidesPerView: "auto",
  direction: "horizontal",
  freeMode: true,
  freeModeMomentum: false,
  freeModeMomentumBounce: false,
  breakpoints: {
    768: {
      slidesPerView: "auto",
      spaceBetween: 30,
    },
  },
});
var swiperPartners = new Swiper(".looksCatsSwiper", {
  spaceBetween: 10,
  slidesPerView: "auto",
  direction: "horizontal",
  freeMode: true,
  freeModeMomentum: false,
  freeModeMomentumBounce: false,
  breakpoints: {
    768: {
      slidesPerView: "auto",
      spaceBetween: 30,
    },
  },
});

const lookSlider = document.querySelectorAll('.lookSwiper');

for (i = 0; i < lookSlider.length; i++) {

  lookSlider[i].classList.add('lookSwiper-' + i);

  var slider = new Swiper('.lookSwiper-' + i, {
    spaceBetween: 10,
    slidesPerView: 'auto',
    breakpoints: {

      1024: {
        slidesPerView: 4,
        spaceBetween: 20,
      },
    },
  });

}

var swiperThumbs = new Swiper(".galThumbsSwiper", {
  spaceBetween: 10,
  slidesPerView: 'auto',
  direction: "vertical",
  freeMode: true,
  watchSlidesProgress: true,
});
var swiperMain = new Swiper(".galMainSwiper", {
  effect: "fade",
  thumbs: {
    swiper: swiperThumbs,
  },
});
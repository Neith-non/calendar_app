// assets/js/pdf_modal.js

async function openPdfModal() {
  let modal = document.getElementById("pdfModal");

  if (!modal) {
    modal = document.createElement("div");
    modal.id = "pdfModal";
    modal.className = "fixed inset-0 bg-slate-900/60 hidden items-center justify-center z-[110] backdrop-blur-sm transition-opacity p-4";
    document.body.appendChild(modal);

    const currentDate = new Date();
    const currentMonth = currentDate.getMonth() + 1;
    const currentYear = currentDate.getFullYear();

    const monthsList = {
      1: "Jan", 2: "Feb", 3: "Mar", 4: "Apr", 5: "May", 6: "Jun",
      7: "Jul", 8: "Aug", 9: "Sep", 10: "Oct", 11: "Nov", 12: "Dec",
    };

    let checkboxesHtml = "";
    for (let num = 1; num <= 12; num++) {
      const isChecked = num === currentMonth ? "checked" : "";
      checkboxesHtml += `
          <label class="flex items-center space-x-2 p-2.5 rounded-xl border border-[#d1f0e0] dark:border-[#123f29] bg-white dark:bg-[#07160f] hover:bg-[#f0fcf5] dark:hover:bg-[#103322] cursor-pointer transition shadow-sm">
              <input type="checkbox" name="months[]" value="${num}" ${isChecked} class="rounded border-emerald-300 text-emerald-600 focus:ring-emerald-500 w-4 h-4 bg-white dark:bg-[#04120a]"> 
              <span class="text-sm text-slate-700 dark:text-slate-300 font-bold">${monthsList[num]}</span>
          </label>
      `;
    }

    // Detect if we are inside the admin folder to fix file paths
    let actionUrl = "generate_pdf.php";
    let apiUrl = "functions/get_categories_api.php";
    if (window.location.pathname.includes("/admin/")) {
      actionUrl = "../generate_pdf.php"; 
      apiUrl = "../functions/get_categories_api.php";
    }

    // Fetch the dynamic categories!
    let categoriesHtml = '<div class="text-sm text-slate-400 italic col-span-full">Loading categories...</div>';
    try {
        const response = await fetch(apiUrl);
        if (response.ok) {
            const categories = await response.json();
            if (categories.length > 0) {
                categoriesHtml = categories.map(cat => `
                    <label class="flex items-center space-x-2 p-2.5 rounded-xl border border-[#d1f0e0] dark:border-[#123f29] bg-white dark:bg-[#07160f] hover:bg-[#f0fcf5] dark:hover:bg-[#103322] cursor-pointer transition shadow-sm">
                        <input type="checkbox" name="categories[]" value="${cat.category_id}" checked class="rounded border-emerald-300 text-emerald-600 focus:ring-emerald-500 w-4 h-4 bg-white dark:bg-[#04120a]">
                        <span class="text-sm text-slate-700 dark:text-slate-300 font-bold truncate" title="${cat.category_name}">${cat.category_name}</span>
                    </label>
                `).join('');
            } else {
                categoriesHtml = '<div class="text-sm text-red-500 dark:text-red-400 col-span-full font-semibold">No categories found.</div>';
            }
        }
    } catch (error) {
        categoriesHtml = '<div class="text-sm text-red-500 dark:text-red-400 col-span-full font-semibold">Error loading categories.</div>';
    }

    modal.innerHTML = `
      <div class="bg-white dark:bg-[#0b1120] rounded-[2rem] shadow-2xl w-full max-w-lg overflow-hidden border border-[#d1f0e0] dark:border-[#123f29] flex flex-col max-h-[90vh] transform transition-all scale-95 opacity-0" id="pdfModalContent">
          
          <div class="bg-[#f0fcf5] dark:bg-[#0a1a12] p-6 flex justify-between items-start border-b border-[#d1f0e0] dark:border-[#123f29] flex-shrink-0">
              <h2 class="text-xl font-extrabold text-emerald-900 dark:text-emerald-100 leading-tight pr-4 flex items-center">
                  <i class="fa-solid fa-file-pdf text-emerald-500 mr-2"></i> Generate Schedule
              </h2>
              <button type="button" onclick="closePdfModal()" class="text-emerald-400 hover:text-red-500 transition bg-white dark:bg-[#07160f] border border-[#d1f0e0] dark:border-[#123f29] hover:border-red-200 rounded-full w-8 h-8 flex items-center justify-center shadow-sm shrink-0">
                  <i class="fa-solid fa-xmark text-sm"></i>
              </button>
          </div>

          <form action="${actionUrl}" method="GET" class="p-6 overflow-y-auto custom-scrollbar" target="_blank">
              
              <h3 class="text-[10px] font-bold text-emerald-400 uppercase tracking-widest mb-3">1. Select Months</h3>
              <div class="grid grid-cols-3 gap-3 mb-6">
                  ${checkboxesHtml}
              </div>

              <h3 class="text-[10px] font-bold text-emerald-400 uppercase tracking-widest mb-3">2. Select Categories</h3>
              <div class="grid grid-cols-2 gap-3 mb-6">
                  ${categoriesHtml}
              </div>

              <h3 class="text-[10px] font-bold text-emerald-400 uppercase tracking-widest mb-3">3. Display Options</h3>
              <div class="grid grid-cols-2 gap-2 mb-6 bg-[#f0fcf5] dark:bg-[#0a1a12] p-4 rounded-xl border border-[#d1f0e0] dark:border-[#123f29]">
                  <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300 font-semibold cursor-pointer hover:text-emerald-600 dark:hover:text-emerald-400 transition">
                      <input type="checkbox" name="show_cat" value="1" checked class="w-4 h-4 rounded border-emerald-300 text-emerald-600 focus:ring-emerald-500 bg-white dark:bg-[#04120a]"> Category
                  </label>
                  <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300 font-semibold cursor-pointer hover:text-emerald-600 dark:hover:text-emerald-400 transition">
                      <input type="checkbox" name="show_ven" value="1" checked class="w-4 h-4 rounded border-emerald-300 text-emerald-600 focus:ring-emerald-500 bg-white dark:bg-[#04120a]"> Venue
                  </label>
                  <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300 font-semibold cursor-pointer hover:text-emerald-600 dark:hover:text-emerald-400 transition">
                      <input type="checkbox" name="show_part" value="1" checked class="w-4 h-4 rounded border-emerald-300 text-emerald-600 focus:ring-emerald-500 bg-white dark:bg-[#04120a]"> Participants
                  </label>
                  <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300 font-semibold cursor-pointer hover:text-emerald-600 dark:hover:text-emerald-400 transition">
                      <input type="checkbox" name="show_cust" value="1" checked class="w-4 h-4 rounded border-emerald-300 text-emerald-600 focus:ring-emerald-500 bg-white dark:bg-[#04120a]"> Custom Times
                  </label>
              </div>

              <h3 class="text-[10px] font-bold text-emerald-400 uppercase tracking-widest mb-2">4. Print Size</h3>
              <select name="paper_size" class="w-full p-3 bg-[#f0fcf5] dark:bg-[#0a1a12] border border-[#d1f0e0] dark:border-[#123f29] rounded-xl text-sm text-emerald-900 dark:text-emerald-100 font-semibold focus:outline-none focus:ring-2 focus:ring-emerald-500 mb-2 shadow-sm">
                <option value="a4">A4 (8.27" x 11.69")</option>  
                <option value="letter">Short Bond (Letter - 8.5" x 11")</option>
                <option value="legal">Long Bond (Legal - 8.5" x 14")</option>
              </select>

              <input type="hidden" name="year" value="${currentYear}">
              
              <div class="flex justify-end gap-3 pt-6 mt-4 border-t border-[#d1f0e0] dark:border-[#123f29]">
                  <button type="button" onclick="closePdfModal()" class="bg-white dark:bg-[#0a1a12] border border-[#d1f0e0] dark:border-[#123f29] hover:bg-[#f0fcf5] dark:hover:bg-[#103322] text-emerald-800 dark:text-emerald-200 font-bold py-2.5 px-6 rounded-xl transition shadow-sm text-sm">
                      Cancel
                  </button>
                  <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-6 rounded-xl transition shadow-sm text-sm flex items-center gap-2">
                      <i class="fa-solid fa-print"></i> Generate PDF
                  </button>
              </div>
          </form>
      </div>
    `;
  }

  // Animate the modal opening
  modal.classList.remove("hidden");
  modal.classList.add("flex");
  
  setTimeout(() => {
      const content = document.getElementById('pdfModalContent');
      if (content) content.classList.remove('scale-95', 'opacity-0');
  }, 10);
}

function closePdfModal() {
  const modal = document.getElementById("pdfModal");
  const content = document.getElementById("pdfModalContent");
  
  if (content) {
      content.classList.add('scale-95', 'opacity-0');
  }
  
  setTimeout(() => {
      if (modal) {
          modal.classList.add("hidden");
          modal.classList.remove("flex");
      }
  }, 200);
}
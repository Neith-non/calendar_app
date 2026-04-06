// assets/js/pdf_modal.js

function openPdfModal() {
    // 1. Check if the modal already exists on the page
    let modal = document.getElementById('pdfModal');

    // 2. If it doesn't exist, we build it dynamically!
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'pdfModal';
        modal.className = 'fixed inset-0 bg-slate-900 bg-opacity-50 hidden items-center justify-center z-50 backdrop-blur-sm transition-opacity';

        // Get the current date to auto-check the current month and set the year
        const currentDate = new Date();
        const currentMonth = currentDate.getMonth() + 1; // JS months are 0-11, so add 1
        const currentYear = currentDate.getFullYear();

        const monthsList = {
            1: 'Jan', 2: 'Feb', 3: 'Mar', 4: 'Apr', 5: 'May', 6: 'Jun',
            7: 'Jul', 8: 'Aug', 9: 'Sep', 10: 'Oct', 11: 'Nov', 12: 'Dec'
        };

        // Build the checkboxes
        let checkboxesHtml = '';
        for (let num = 1; num <= 12; num++) {
            const isChecked = (num === currentMonth) ? 'checked' : '';
            checkboxesHtml += `
                <label class="flex items-center space-x-2 p-2 rounded border border-slate-200 hover:bg-slate-50 cursor-pointer transition">
                    <input type="checkbox" name="months[]" value="${num}" ${isChecked} class="rounded text-blue-600 focus:ring-blue-500 w-4 h-4"> 
                    <span class="text-sm text-slate-700 font-semibold">${monthsList[num]}</span>
                </label>
            `;
        }

        // Build the full modal HTML
        modal.innerHTML = `
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-md overflow-hidden">
                <div class="bg-slate-800 p-4 flex justify-between items-center text-white">
                    <h2 class="text-xl font-bold"><i class="fa-solid fa-file-pdf text-red-400 mr-2"></i> Generate Schedule</h2>
                    <button type="button" onclick="closePdfModal()" class="text-slate-300 hover:text-white transition">
                        <i class="fa-solid fa-xmark text-xl"></i>
                    </button>
                </div>

                <form action="generate_pdf.php" method="GET" class="p-6">
                    <p class="text-sm text-slate-600 font-medium mb-4">Select the months you want to include in the document:</p>
                    
                    <div class="grid grid-cols-3 gap-3 mb-6">
                        ${checkboxesHtml}
                    </div>

                    <input type="hidden" name="year" value="${currentYear}">
                    
                    <div class="flex justify-end gap-3 pt-4 border-t border-slate-100">
                        <button type="button" onclick="closePdfModal()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold rounded-lg transition">Cancel</button>
                        <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg transition shadow-md flex items-center gap-2">
                            Generate PDF
                        </button>
                    </div>
                </form>
            </div>
        `;
        
        // Inject it into the bottom of the body tag
        document.body.appendChild(modal);
    }

    // 3. Show the modal
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closePdfModal() {
    const modal = document.getElementById('pdfModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}